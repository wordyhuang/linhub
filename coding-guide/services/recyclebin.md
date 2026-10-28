# LinHub 服务接入指南 · recyclebin 回收站服务 V1.0

> **文档创建时间**：2026-10-27 23:47:00
> **最后修改时间**：2026-10-27 23:47:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 recyclebin 回收站服务的契约方法、核心语义、配额与包间隔离规则。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 recyclebin 一个服务；功能包删除业务数据前将任意结构数据快照入站托管，此后可恢复（取回数据自行写回）或彻底删除，支持保留期与配额，包卸载孤儿态保留、重装接管。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 核心语义（快照 / 恢复 / 保留期 / 配额）
5. 包间隔离与生命周期
6. 使用要点与避坑
7. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `recyclebin` |
| 契约接口 | `linhub\host\services\recyclebin\RecycleBin` |
| 宿主实现 | `linhub\host\services\recyclebin\RecycleBinService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['recyclebin'], ...]

$rb = HostServiceApi::getService($this->app, 'recyclebin', 'demo');

// 删除业务数据前入站托管：返回条目 token（32 字符随机 hex）
$token = $rb->put(null, 'demo_orders', $orderId, $orderRow, [
    'itemKey' => (string)$orderNo,      // 业务键（可选）
    'title'   => "订单 {$orderNo}",      // 展示标题（可选）
    'meta'    => ['reason' => '用户删除'], // 附加元信息（可选，原样取回）
    'ttlDays' => 30,                     // 保留天数（可选，缺省/0 = 永久）
]);

// 恢复：返回数据副本，条目保留；写回原表由包自己完成
$data = $rb->restore(null, $token);

// 彻底删除
$rb->purge(null, $token);
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `put` | `put(?string $package, string $sourceTable, string|int $sourcePk, mixed $payload, array $options = [], bool $asAdmin = false): string` | 入站托管：任意结构 payload（可 json_encode），返回条目 token；options 驼峰键 `itemKey`/`title`/`meta`/`ttlDays`；超配额抛 `RecycleBinQuota` |
| `get` | `get(?string $package, string $token, bool $asAdmin = false): ?array` | 取单条（payload 已 json_decode）；不存在返回 null；他包条目抛 `RecycleBinDenied` |
| `list` | `list(?string $package, array $filter, int $page = 1, int $size = 20, bool $asAdmin = false): array` | 检索分页（本包视角）；filter 下划线键：`namespace`/`source_table`/`item_key`/`keyword`/`orphaned`；`filter.namespace` 仅超管跨包用 |
| `restore` | `restore(?string $package, string $token, bool $asAdmin = false): array` | 恢复：返回条目数据副本（payload + 溯源字段），**条目保留不删除**；写回由包自己完成；条目不存在抛异常 |
| `purge` | `purge(?string $package, string $token, bool $asAdmin = false): bool` | 彻底删除单条；条目不存在返回 false |
| `purgeBatch` | `purgeBatch(?string $package, array $tokens, bool $asAdmin = false): int` | 批量彻底删除，返回实际删除条数；任一条目无权操作时整批拒绝 |
| `stats` | `stats(): array` | 统计：总数 / 孤儿数 / 7 日内到期数 / 按命名空间聚合（管理 GUI 视角，无需传包） |
| `purgeExpired` | `purgeExpired(?string $package = null, bool $asAdmin = false): int` | 清理到期条目；`$package=null` 且 asAdmin 清全部命名空间；返回清理条数 |

---

## 五、核心语义（快照 / 恢复 / 保留期 / 配额）

- **快照托管**：`put` 时 payload 整体 JSON 序列化快照落库（`lg_mgt_recyclebin` 表），与原表数据此后互不关联；溯源字段 `source_table`/`source_pk` 仅用于展示与过滤。
- **restore 只还数据不写回**：恢复返回数据副本（含溯源字段），条目保留可重复恢复；主键冲突、结构漂移等写回问题由功能包自行处理——服务不替包决策。
- **保留期**：`ttlDays` 缺省/0 = **永久保留**；到期条目不会自动消失，由 `purgeExpired` 清理（管理端触发或平台维护动作）。
- **配额**：单条 payload 上限 **1MB**、单包条目数上限 **1000 条**，超出抛 `RecycleBinQuota`。
- **token 自持**：put 返回的 token 是后续 get/restore/purge 的唯一定位键，功能包须自行持久化 token（或经 `list` 按 itemKey/source_table 找回）。

---

## 六、包间隔离与生命周期

- **全私有隔离**：回收站数据天然全私有，无公开概念——读操作本包 namespace 全量可读，他包条目一律不可读（get/list 均不可见他包）；写 / 删 / 恢复仅允许 caller 操作自己包的 namespace，越权抛 `RecycleBinDenied`。
- **`$package = null`**：消费侧默认形态，静态回溯调用链判定 caller 所属功能包（与 Translator/Dictionary 一致）。
- **asAdmin 跨包**：`asAdmin = true` 要求超管权限（权限点 `host:recyclebin:manage`），可操作任意 namespace；`filter.namespace` 跨包检索亦仅超管。
- **host 保留命名空间**：`host` namespace 仅平台自身可读写，功能包不可写、不可读。
- **包卸载（孤儿态）**：包卸载时回收站条目**保留**并标记孤儿态（`orphaned`），不随包删除——防误删数据丢失。
- **重装接管**：同名包重装后自动接管原孤儿态条目，恢复为本包可见可操作数据。

---

## 七、使用要点与避坑

1. **先 put 再删原表**：删除业务数据的正确顺序是 put 快照成功拿到 token 后，再删原表记录；put 抛 `RecycleBinQuota` 时应中止删除。
2. **token 要持久化**：token 是唯一定位键，只在 put 返回一次；不存 token 就只能靠 `list` 的 item_key/source_table/keyword 过滤找回。
3. **不要指望 restore 自动写回**：restore 只返回数据副本；写回原表的时机、冲突处理是功能包自己的业务逻辑。
4. **配额是硬约束**：单条 1MB / 单包 1000 条，大批量删除场景注意分批 purge 释放额度。
5. **options 用驼峰、filter 用下划线**：put options 是 `itemKey`/`ttlDays`，list filter 是 `source_table`/`item_key`，两套键风格不要混用。
6. **purgeBatch 整批拒绝**：批量彻底删除时任一条目无权操作会整批拒绝，跨包混合 token 列表要拆分处理。

---

## 版本变化记录

- **V1.0（2026-10-27）**：初版。回收站服务（平台第 16 个公共服务）随服务上线拆分独立成文。
