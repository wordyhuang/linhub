# LinHub 服务接入指南 · scheduler 异步任务托管服务 V1.1

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-10 12:43:00
> **当前版本**：V_1.1
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 scheduler 异步任务托管服务的接入方式、10 个契约方法、处理器写法、执行模型与 42 项配置，让功能包安全地提交一次性任务与声明周期任务。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 scheduler 一个服务；服务已由宿主内置实现（`SchedulerService`，v1.1.0），可注入。功能包在包根 `tasks.php` 声明处理器，通过注入实例提交任务或定义周期任务，由 Lite（请求尾）或 Pro（常驻心跳）执行；队列与并发受三级闸门约束，支持租约回收、幂等、取消、熔断与完成通知。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 处理器声明与编写（`tasks.php`）
4. 契约方法速查
5. 提交与周期定义语义
6. 执行模型
7. 配置项（42 项）
8. 运维入口（CLI / 管理端）
9. 数据表与时间口径
10. 已知限制
11. 使用要点与避坑
12. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `scheduler` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 不支持（任务与实例不随包导出） |
| 契约接口 | `linhub\host\services\scheduler\TaskScheduler` |
| 宿主实现 | `linhub\host\services\scheduler\SchedulerService` |
| 版本 | 1.1.0 |
| 状态 | 已实现，可注入 |
| 配置存储 | `data/platform/scheduler_setting.json`（42 项平铺键值） |
| 数据表 | `lg_mgt_task_def` / `lg_mgt_task_run` / `lg_mgt_task_idem` / `lg_mgt_task_heartbeat` / `lg_mgt_task_kv` |
| 管理端 | `/admin/scheduler_admin/index`（菜单「任务调度」，权限 `host:scheduler:manage`） |

异常类（均在 `linhub\host\services\scheduler\exception\` 下，继承链为 `\RuntimeException` → `SchedulerException` → 具体异常）：

| 异常 | 触发场景 |
| --- | --- |
| `SchedulerException` | 服务未启用、定义键非法、定义不存在、配置项取值非法 |
| `TaskInvalidHandler` | 处理器标识为空或超过 191 字符、`tasks.php` 未声明、类不存在或未实现 `TaskHandler` |
| `TaskInvalidSchedule` | `mode` 非 `interval/cron`、表达式非法 |
| `TaskPayloadTooLarge` | payload 序列化后超过 `scheduler.quota.max_payload_bytes` |
| `TaskQueueFull` | 队列超限且 `scheduler.queue.overflow_policy = reject` |
| `TaskQuotaExceeded` | 新增周期定义数达到 `scheduler.quota.max_periodic_defs_per_package` |
| `TaskAccessDenied` | 跨包访问、包身份无法绑定 |

---

## 三、接入方式

### 3.1 manifest 声明

```php
// manifest.php
'services' => ['requires' => ['scheduler']],
// 可选：处理器文件名，缺省即 tasks.php（安装期只做静态校验）
'tasks' => 'tasks.php',
```

### 3.2 取服务实例

```php
use linhub\host\communication\HostServiceApi;

$scheduler = HostServiceApi::getService($this->app, 'scheduler', 'demo');
```

- 第三个参数是**包短名**（`manifest.php` 的 `short`），必须传；漏传会被焊死为 `pkg.unknown`，任务归属失真。
- 拿到的实例是 `BoundScheduler`（再外层套一层调用统计代理），**已焊死包身份**：方法签名里没有 `namespace` 参数，包内无需也不能传；读写只作用于本包数据，跨包访问抛 `TaskAccessDenied`。
- 因此**不要用 `instanceof TaskScheduler` 判断注入对象类型**（装饰器链会失真）；需要调试时可调用 `BoundScheduler::boundNamespace()` 查看绑定包名。

> 完整服务清单与通用接入规则见 [08_平台服务接入指引.md](../08_平台服务接入指引.md)。

---

## 四、处理器声明与编写（`tasks.php`）

### 4.1 声明文件

包根（`code/plugins/<short>/`）下新建 `tasks.php`，**只返回数组**：键=处理器标识，值=实现类 FQCN。

```php
<?php

declare(strict_types=1);

return [
    // 键：处理器标识，建议带包名前缀，1~191 字符
    'demo.report.export' => \demo\handler\ReportExportHandler::class,
    'demo.cache.warm'    => \demo\handler\CacheWarmHandler::class,
];
```

约束：

- 键须匹配 `^[a-z][a-z0-9._-]{0,63}$`（manifest 校验 M016），长度 1~191；
- 值必须是本包 PSR-4 前缀下的类（`composer.json` 的 `autoload.psr-4`），排除函数名/闭包/跨包类；
- 安装期**不检查** `class_exists`（AC-46），运行期解析失败判 `failed`，不自动重试；
- 文件缺失只告警不阻断安装；但**运行时固定加载包根 `tasks.php`**，改 manifest 的 `tasks` 文件名目前不生效（见「已知限制」）。

### 4.2 处理器实现

```php
<?php

declare(strict_types=1);

namespace demo\handler;

use linhub\host\services\scheduler\TaskContext;
use linhub\host\services\scheduler\TaskHandler;

final class ReportExportHandler implements TaskHandler
{
    public function handle(TaskContext $context): array
    {
        $reportId = (int) ($context->payload()['report_id'] ?? 0);
        for ($i = 0; $i <= 100; $i += 10) {
            // 上报进度（0~100）；写库失败不抛异常
            $context->progress($i, '正在导出第 ' . $i . ' 批');
            // 长任务必须周期性续租 + 检查取消；返回 false 表示已取消或租约丢失
            if (!$context->sleep(1)) {
                return ['cancelled' => true];
            }
        }
        return ['report_id' => $reportId, 'rows' => 1200];
    }
}
```

`TaskContext` 方法：

| 方法 | 说明 |
| --- | --- |
| `runId(): string` | 实例 id（32 位十六进制） |
| `package(): string` | 当前包短名 |
| `taskKey(): string` | 提交时的 `key`（周期任务为定义键） |
| `payload(): array` | 提交时的载荷（已 JSON 解码） |
| `timeoutSec(): int` | 单任务硬上限（当前取全局配置，见「已知限制」） |
| `progress(int $percent, string $message = '')` | 上报进度（0~100）与消息 |
| `heartbeat(): bool` | 续租租约并检查取消；`false` 表示已被取消或租约丢失，应立即退出 |
| `cancelled(): bool` | 只检查取消请求 |
| `sleep(int $seconds): bool` | 分段睡眠（每 0.5 秒检查一次取消），被取消时提前返回 `false` |

处理器约定：

- 必须可**无参实例化**（宿主 `new $class()`）；
- `handle()` 返回数组，作为 `task_run.result` 持久化；超过 `scheduler.quota.max_result_bytes` 时改写为 `{"truncated":true,"bytes":N}`；
- 抛异常 → 实例 `failed`，`error` 截断 2000 字符；
- 长任务（预计超过 `scheduler.task.heartbeat_hint_sec`，默认 30 秒）必须调用 `heartbeat()`，否则租约超时后实例被回收为可重跑（可能重复执行）。

---

## 五、契约方法速查

下表中的签名均为**注入实例（`BoundScheduler`）视角**，`namespace` 由宿主自动补上。

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `submit` | `submit(string $handler, array $payload = [], array $options = []): string` | 提交一次性任务，返回 32 位 hex `run_id` |
| `define` | `define(string $key, string $handler, string $expr, array $options = []): array` | 新增/覆盖周期定义，返回 `def_key/handler/mode/expr/status/enabled/next_run` 快照 |
| `pause` | `pause(string $key): bool` | 暂停周期定义（已生成的实例不受影响） |
| `resume` | `resume(string $key): bool` | 恢复并重算 `next_run` |
| `triggerNow` | `triggerNow(string $key, array $payload = []): string` | 立即触发一次（不改动原定 `next_run`），返回 `run_id`；定义不存在抛 `SchedulerException` |
| `nextRun` | `nextRun(string $key): ?string` | 下次触发时间（ISO 8601）；不存在/未排期返回 `null` |
| `status` | `status(string $runId): ?array` | 实例状态；跨包或不存在返回 `null` |
| `cancel` | `cancel(string $runId): bool` | `queued` 直接取消；`running` 打取消标记，由处理器自行退出；终态返回 `false` |
| `list` | `list(array $filter = [], int $page = 1, int $size = 20): array` | 分页列出周期定义，返回 `{items,total,page,size}` |
| `runs` | `runs(array $filter = [], int $page = 1, int $size = 20): array` | 分页列出执行实例，返回 `{items,total,page,size}` |

`status()` 返回字段：`run_id / task_key / handler / state / progress(int) / message / attempt / error / cancel_requested(int) / result(已解码) / created_at / started_at / finished_at`（**不含 payload**）。

`list()` 过滤键：`def_key`、`status`；`runs()` 过滤键：`state`、`handler`、`task_key`、`def_id`、`date_from`、`date_to`。分页 `page >= 1`，`size` 夹取 1~200。

---

## 六、提交与周期定义语义

### 6.1 `submit` 的 options

| 键 | 类型 | 生效 | 说明 |
| --- | --- | --- | --- |
| `key` | string | 是 | 任务键，写入 `task_run.task_key`，便于检索 |
| `delay_sec` | int | 是 | 延迟秒数，优先于 `plan_time` |
| `plan_time` | string | 是 | ISO 时间（可带偏移），`delay_sec` 为 0 时使用 |
| `priority` | int | 是 | 队列优先级，越大越先执行 |
| `idem_key` | string | 是 | 幂等键；`scheduler.idempotency.enabled` 开启时命中直接返回既有 `run_id` |
| `created_by` | string | 是 | 提交人；完成通知（站内信）投递给该用户，为空则不通知 |
| `timeout_sec` | int | **否** | 当前不落库，执行统一用全局 `scheduler.task.timeout_sec` |
| `notify` | bool | **否** | 当前不读取，通知只受 `scheduler.notify.default_on_done` 控制 |

提交链路：服务启用检查 → 处理器标识校验 → payload 序列化与大小校验 → 幂等命中检查 → 队列容量检查 → 生成 `run_id` → 计算 `plan_time` → 落库 → 写幂等记录。

### 6.2 `define` 的 options

| 键 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `mode` | string | `interval` | `interval`（expr 为秒数）/ `cron`（标准五段） |
| `timezone` | string | 继承既有，空则 `UTC` | 计算 `next_run` 的时区 |
| `payload` | array | 继承既有 | 定义载荷；不传时沿用旧值 |
| `enabled` | bool | `true` | 新建即停用时传 `false` |
| `timeout_sec` | int | 全局上限 | 写入定义行；实际执行仍取全局值 |
| `created_by` | string | `''` | 定义创建人 |

周期表达式：

- `interval`：正整数秒数（最小 1 秒）；
- `cron`：五段（分 时 日 月 周），按 `timezone` 计算；
- 表达式失效（运行期抛异常）→ 定义自动 `enabled=0`、`status=paused`、`last_status=invalid_schedule`，需修好后手动 `resume`。

### 6.3 状态机

```
queued ──认领──> running ──完成──> done
   │                ├──异常/处理器解析失败──> failed
   │                └──取消请求被响应──────> cancelled
   └──直接取消──> cancelled
```

- `queued`：可被 `cancel` 直接取消；
- `running`：`cancel` 只写 `cancel_requested=1`，处理器必须通过 `heartbeat()`/`cancelled()` 响应并自行返回；
- `done/failed/cancelled` 为终态，`cancel` 返回 `false`；
- 失败不自动重试（`retry_of` 字段预留）。

### 6.4 周期实例生成与失败暂停

- 每次 tick 取 `next_run <= now` 的定义（单次最多 50 个），按 `(def_id, plan_time)` 唯一键插入实例，多进程不重复；
- `next_run` 推进策略由 `scheduler.periodic.missed_policy` 决定：`skip`（从当前时间推进）/ `catchup_once`（从上次计划时间推进，只补一次）；
- 同一实例执行失败累计达 `scheduler.periodic.fail_pause_after`（默认 3）→ 定义自动 `enabled=0`、`status=paused` 并记告警日志。

### 6.5 队列与配额

| 项 | 默认 | 行为 |
| --- | --- | --- |
| `scheduler.queue.max_queued_per_package` | 50 | 单包排队实例上限 |
| `scheduler.queue.max_queued_global` | 500 | 全局排队实例上限 |
| `scheduler.queue.overflow_policy` | `reject` | `reject` 抛 `TaskQueueFull`；`allow` 不拦截 |
| `scheduler.quota.max_periodic_defs_per_package` | 20 | 单包周期定义上限，超出抛 `TaskQuotaExceeded` |

---

## 七、执行模型

### 7.1 双档触发

| 档位 | 触发源 | 精度 | 适用 |
| --- | --- | --- | --- |
| `lite`（默认） | 请求尾 `fastcgi_finish_request()` 后薄跑一次 tick；或 cron/schtasks 定时 `--once` | 请求尾约 10 秒节流；cron 60 秒 | 虚拟主机、轻量部署 |
| `pro` | 常驻心跳 `--loop --interval=N`（需自行守护） | 心跳间隔（默认 5 秒） | 独立服务器 / systemd |

`lite.trigger` 枚举：`auto`（请求尾 + 可配定时任务）、`cron_once`（只依赖定时任务）、`request_tail`（只走请求尾）。CLI 进程不注册请求尾触发。

### 7.2 一次 tick 的顺序

1. 抢单次 tick 互斥锁（`flock` 非阻塞），失败直接返回「另一个 tick 正在执行，本次跳过」；
2. `reclaimStaleRuns`：回收超过 `lease_sec + stale_grace_sec` 未续租的 `running` 实例；
3. `materializeDueDefs`：到期定义生成实例并推进 `next_run`；
4. `claimableRuns`：按 `state/priority/plan_time` 取候选（上限 `max(batch*5, 20)`）；
5. 闸门内逐个认领（条件 UPDATE，影响行数 1 才成功）并**同步执行**；
6. `cleanup`（10 分钟节流：清过期幂等、清过期终态实例）→ 写心跳与 `scheduler.last_tick`。

单次 tick 受 `scheduler.exec.heartbeat_budget_sec`（默认 30 秒）与 `scheduler.exec.heartbeat_batch`（默认 10 个）约束，超出即停止认领。

### 7.3 三级闸门与熔断

| 闸门 | 配置 | 默认 |
| --- | --- | --- |
| 全局并发 | `scheduler.exec.global_max_concurrent` | 8 |
| 单包并发 | `scheduler.exec.package_max_concurrent` | 3 |
| 单包每 tick 认领数 | `scheduler.exec.package_batch_per_tick` | 2 |
| 单处理器熔断 | `scheduler.circuit.enabled` + 窗口/阈值/样本/冷却 | 开启，300s / 0.5 / 5 / 300s |

熔断状态存 `task_kv` 键 `scheduler.circuit.<namespace>.<handler>`；窗口内样本数达标且失败率达阈值即打开冷却，期间该处理器任务跳过（`skipped`）。

### 7.4 取消、租约与长任务

- 执行期间 `TaskContext::progress()` 与 `heartbeat()` 会同时续租 `heartbeat_at`；
- 取消请求写 `cancel_requested=1`，处理器下次 `heartbeat()` 返回 `false`，宿主在收尾时把状态记 `cancelled`；
- 租约默认 90 秒（`scheduler.task.lease_sec`），建议处理器不超过 `heartbeat_hint_sec`（默认 30 秒）就续租一次。

### 7.5 完成通知与日志

- 通知：实例到终态时，若 `scheduler.notify.default_on_done=true` 且 `created_by` 非空，经站内消息服务（`inbox`）向提交人发一条「异步任务已完成 / 已取消 / 失败」，级别 `info`（失败为 `warning`）；发送失败只吞掉，不影响实例状态。
- 告警：熔断打开、周期定义连续失败自动暂停等事件写告警日志；`scheduler.alert.channel` 当前未参与行为（只写日志）。
- 日志：`data/log/scheduler/scheduler-YYYYMMDD.log`，行格式 `[ISO 时间] LEVEL 内容`。

---

## 八、配置项（42 项）

存储：`data/platform/scheduler_setting.json`。读取顺序：**默认值 → 文件覆盖 → env 硬顶**（`SCHEDULER_MAX_CONCURRENT` 默认 64、`SCHEDULER_MAX_TIMEOUT_SEC` 默认 1800）。保存时按范围/枚举白名单归一化，任一非法项整体抛 `SchedulerException('配置项取值非法：key')`。

### 8.1 总开关与执行

| 键 | 默认 | 范围/枚举 |
| --- | --- | --- |
| `scheduler.enabled` | `true` | bool |
| `scheduler.mode` | `lite` | `lite` / `pro` |
| `scheduler.lite.trigger` | `auto` | `auto` / `cron_once` / `request_tail` |
| `scheduler.mode.switch_drain_sec` | 60 | 10~600 |
| `scheduler.exec.global_max_concurrent` | 8 | 1~64（env 硬顶） |
| `scheduler.exec.package_max_concurrent` | 3 | 1~20 |
| `scheduler.exec.heartbeat_batch` | 10 | 1~50 |
| `scheduler.exec.package_batch_per_tick` | 2 | 1~10 |
| `scheduler.exec.heartbeat_budget_sec` | 30 | 1~120 |
| `scheduler.exec.heartbeat_interval_sec` | 5 | 1~3600 |
| `scheduler.exec.lock_wait_sec` | 5 | 1~30 |

### 8.2 任务与租约

| 键 | 默认 | 范围 |
| --- | --- | --- |
| `scheduler.task.timeout_sec` | 600 | 60~1800（env 硬顶 `SCHEDULER_MAX_TIMEOUT_SEC`） |
| `scheduler.task.lease_sec` | 90 | 30~600 |
| `scheduler.task.heartbeat_hint_sec` | 30 | 10~300 |
| `scheduler.task.stale_grace_sec` | 30 | 0~120 |

### 8.3 队列与配额

| 键 | 默认 | 范围/枚举 |
| --- | --- | --- |
| `scheduler.queue.max_queued_per_package` | 50 | 10~500 |
| `scheduler.queue.max_queued_global` | 500 | 50~5000 |
| `scheduler.queue.overflow_policy` | `reject` | `reject` / `allow` |
| `scheduler.queue.max_runs_per_package` | 500 | 100~5000 |
| `scheduler.queue.terminal_retention_days` | 7 | 1~90 |
| `scheduler.quota.max_payload_bytes` | 1048576 | 65536~4194304 |
| `scheduler.quota.max_result_bytes` | 1048576 | 65536~4194304 |
| `scheduler.quota.max_log_lines` | 200 | 50~1000 |
| `scheduler.quota.max_periodic_defs_per_package` | 20 | 1~100 |
| `scheduler.quota.min_interval_sec` | 1 | 1~3600 |

### 8.4 周期任务

| 键 | 默认 | 范围/枚举 |
| --- | --- | --- |
| `scheduler.periodic.fail_pause_after` | 3 | 1~10 |
| `scheduler.periodic.max_runs_per_def` | 100 | 20~1000 |
| `scheduler.periodic.failed_retention_days` | 7 | 1~90 |
| `scheduler.periodic.missed_policy` | `skip` | `skip` / `catchup_once` |

### 8.5 熔断、告警、通知、幂等

| 键 | 默认 | 范围/枚举 |
| --- | --- | --- |
| `scheduler.circuit.enabled` | `true` | bool |
| `scheduler.circuit.window_sec` | 300 | 60~1800 |
| `scheduler.circuit.fail_rate_threshold` | 0.5 | 0.1~1.0 |
| `scheduler.circuit.min_samples` | 5 | 3~50 |
| `scheduler.circuit.cooldown_sec` | 300 | 60~3600 |
| `scheduler.alert.enabled` | `true` | bool |
| `scheduler.alert.stale_task_threshold` | 5 | 1~100 |
| `scheduler.alert.fail_rate_threshold` | 0.3 | 0.1~1.0 |
| `scheduler.alert.suppress_sec` | 1800 | 300~86400 |
| `scheduler.alert.channel` | `inbox` | `inbox` / `inbox+log` |
| `scheduler.notify.default_on_done` | `true` | bool |
| `scheduler.idempotency.enabled` | `true` | bool |
| `scheduler.idempotency.ttl_sec` | 86400 | 60~604800 |

---

## 九、运维入口（CLI / 管理端）

### 9.1 命令

三个入口共用同一套参数与退出码：

```bash
# think 控制台
php think linhub:scheduler-heartbeat --once
php think linhub:scheduler-heartbeat --loop --interval=5

# 独立入口（systemd / schtasks 用，不依赖容器启动）
php code/host/scheduler-heartbeat.php --once
php code/host/scheduler-heartbeat.php --loop --interval=5
```

| 参数 | 说明 |
| --- | --- |
| `--once` | 执行一次 tick 后退出（cron / 计划任务） |
| `--loop` | 常驻循环（`--interval` 1~300 秒，默认 1） |
| `--probe` | 输出环境探测（P-1~P-8）与 E0~E3 分级，`--fresh` 跳过 60 秒缓存 |
| `--install` | 只输出 systemd / cron / schtasks 安装命令文本，不执行 |
| `--status` | 输出档位、触发源、心跳、版本、锁持有者与最近一次 tick |

退出码：`0` 成功 / `1` 抢锁失败 / `2` 参数错误 / `3` 运行异常。

环境分级：`E3` 无心跳 / `E2` 非 Pro 且无系统调度器 / `E1` 无 `fastcgi_finish_request` / `E0` 健全。

### 9.2 锁

| 锁文件 | 作用 |
| --- | --- |
| `data/platform/scheduler_tick.lock` | 单次 tick 互斥（请求尾、`--once`、`--loop` 共用） |
| `data/platform/scheduler_heartbeat.lock` | `--loop` 全生命周期进程锁，抢不到抛「另一个心跳进程正在运行，抢锁失败」 |

并发控制以数据库计数（`running` 状态行数）为准，不依赖额外的闸门锁文件；`HostBootstrap` 中预留的 `scheduler_gate.lock` 当前未被使用。

### 9.3 管理端

- 入口：`/admin/scheduler_admin/index`，菜单「任务调度」，权限点 `host:scheduler:manage`；
- 四个页签：概览、周期定义、执行实例、设置（42 项由后端 `schema()` 驱动渲染），另有「环境自检」弹窗与部署命令面板；
- 端点：`index / overview / defs / runs / settings / probe / installCommands`（GET），`pauseDef / resumeDef / deleteDef / triggerNow / cancelRun / settingsSave / settingsReset / tickNow`（POST）；
- 门禁：当前仅超管可进（`gate()` 不校验权限点，权限点只决定菜单是否可见）。

---

## 十、数据表与时间口径

| 表 | 用途 |
| --- | --- |
| `lg_mgt_task_def` | 周期定义（`namespace + def_key` 唯一，`enabled/status/next_run/fail_streak`） |
| `lg_mgt_task_run` | 执行实例（`run_id` 唯一，`def_id + plan_time` 唯一，`state/progress/result/error/cancel_requested`） |
| `lg_mgt_task_idem` | 幂等键（`namespace + idem_key` 唯一，`expires_at` 到期惰性清理） |
| `lg_mgt_task_heartbeat` | 心跳行（`worker=primary`，`pid/os/sapi/mode/code_version/last_tick_ms`） |
| `lg_mgt_task_kv` | 运行态键值（`scheduler.last_tick`、`scheduler.cleanup_at`、熔断状态、探测缓存） |

时间口径：

- 所有时间列均为 `VARCHAR(40)`，存 ISO 8601 带偏移串（形如 `2026-11-10T12:43:00+08:00`），由 PHP 默认时区 `date('c')` 生成；
- 字符串可直接比较排序；周期 `next_run` 计算后转换为 PHP 默认时区再落库；
- 不要用 SQL 的 `NOW()`/`CURDATE()` 与本服务的实例时间做比较，口径会错位。

---

## 十一、已知限制

1. **无子进程**：执行在 tick 所在进程内同步完成，不派生 worker 进程；长任务会占用该请求/心跳进程，请把单个任务控制在分钟级，并用 `heartbeat()` 续租。
2. **单任务超时不独立**：`submit`/`define` 的 `timeout_sec` 不参与执行，统一使用全局 `scheduler.task.timeout_sec`（默认 600 秒）。
3. **`submit` 的 `notify` 不生效**：是否通知只由 `scheduler.notify.default_on_done` 决定，且需要传 `created_by`。
4. **无自动重试**：失败即终态，`retry_of` 字段预留未写入；需要重试请在处理器内部或业务侧重提（可用 `idem_key` 防重）。
5. **周期定义失败暂停后不自动恢复**：需修好后再 `resume`。
6. **部分配置项仅登记未消费**：`scheduler.mode.switch_drain_sec`、`scheduler.queue.max_runs_per_package`、`scheduler.quota.max_log_lines`、`scheduler.quota.min_interval_sec`、`scheduler.periodic.max_runs_per_def`、`scheduler.alert.stale_task_threshold`、`scheduler.alert.fail_rate_threshold`、`scheduler.alert.suppress_sec`、`scheduler.alert.channel` 目前只参与校验与展示，不改变运行行为；告警统一写日志。
7. **Lite 请求尾节流**：同一 PHP 进程内两次请求尾 tick 至少间隔 10 秒；CLI 进程不触发。
8. **服务停用即不写心跳**：`scheduler.enabled=false` 时 tick 直接返回「服务未启用」，环境自检会显示 `E3`。
9. **Pro 需自行守护**：`--loop` 不自动安装守护，请用 systemd / schtasks / nohup 托管。
10. **`tasks` 文件名可自定义但不生效**：manifest 校验接受任意裸 `.php` 文件名，运行时固定加载包根 `tasks.php`。
11. **`adoptPackage` 不自动恢复周期**：卸载冻结后重装接管，定义 `status` 恢复 `active` 但 `enabled` 保持 `0`，需显式 `resume`。
12. **管理端门禁**：`gate()` 仅判超管，`host:scheduler:manage` 只控制菜单可见性。

---

## 十二、使用要点与避坑

1. **处理器必须无参构造**：依赖请在 `handle()` 内自行获取，别用构造参数。
2. **长任务必须续租**：超过 30 秒不调用 `heartbeat()`，租约到期会被回收，可能出现重复执行；处理器应尽量幂等。
3. **取消是协作式的**：`cancel` 不会杀进程，处理器不检查 `heartbeat()`/`cancelled()` 就停不下来。
4. **payload 与结果都有上限**：默认各 1MB；大对象存业务表，任务里只带引用。
5. **幂等键要稳定**：`idem_key` 在 TTL 内命中直接返回旧 `run_id`；用业务唯一键（如单据号 + 动作）拼接。
6. **站点通知需传 `created_by`**：不传就无法投递站内信（服务不猜提交人）。
7. **周期表达式要写时区**：不传 `timezone` 会按 `UTC` 计算，容易错 8 小时。
8. **不要绕过服务写库**：直接改 `lg_mgt_task_*` 会破坏状态机、租约与闸门计数。
9. **跨包一律禁止**：所有方法只作用于绑定包；管理端跨包读写是宿主自身能力，不在契约内。
10. **先跑 `--probe` 再选档**：没有 `fastcgi_finish_request` 的共享主机应选 `cron_once` 或 `pro`，否则请求尾只在响应后同步执行，会拖慢请求。

---

## 十三、版本变化记录

- **V1.1（2026-11-10）**：服务由「仅接口」落地为已实现可注入（`SchedulerService` v1.1.0）。文档全量重写：新增接入方式与包身份焊死规则、`tasks.php` 处理器声明与 `TaskHandler`/`TaskContext` 写法、10 方法契约表、提交/周期语义与状态机、Lite/Pro 执行模型与三级闸门/熔断、42 项配置表、CLI 与管理端入口、5 张数据表与时间口径、已知限制与避坑。
- **V1.0（2026-10-24）**：初版，声明 scheduler 为「仅接口预留、不可注入」，给出契约预告。
