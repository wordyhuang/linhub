# LinHub 服务接入指南 · logging 日志服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 logging 日志服务的契约方法、接入方式与使用要点。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 logging 一个服务；通用接入流程（manifest 声明、HostServiceApi 门面）见总路由。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 使用要点与避坑
5. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `logging` |
| 契约接口 | `linhub\host\services\logging\Logging` |
| 宿主实现 | `linhub\host\services\logging\LoggingService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['logging'], ...]

// 控制器/服务内
use linhub\host\communication\HostServiceApi;

$logging = HostServiceApi::getService($this->app, 'logging', 'demo');
$logging->info('订单已创建', ['order_id' => 42]);
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `info` | `info(string $message, array $context = []): void` | 记录 info 级日志，带结构化上下文 |
| `warning` | `warning(string $message, array $context = []): void` | 记录 warning 级日志 |
| `error` | `error(string $message, array $context = []): void` | 记录 error 级日志 |
| `debug` | `debug(string $message, array $context = []): void` | 记录 debug 级日志 |

---

## 五、使用要点与避坑

1. `$context` 是结构化上下文数组（如 `['package_id' => 'demo', 'order_id' => 42]`），不要把变量拼进 `$message` 字符串，便于检索。
2. 业务异常/失败路径至少记一条 `warning` 或 `error`，不要静默吞掉。
3. 功能包经服务写日志，不直接操作宿主日志存储；日志落盘位置是平台内部约定。
4. 面向契约接口消费，不要 new `LoggingService`。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
