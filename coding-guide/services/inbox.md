# LinHub 服务接入指南 · inbox 站内消息服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 inbox 站内消息服务的契约方法、三种投递模式与收件侧用法。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 inbox 一个服务；投递保留策略由 env MSG_* 配置。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 投递与收件用法
5. 使用要点与避坑
6. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `inbox` |
| 契约接口 | `linhub\host\services\inbox\Inbox` |
| 宿主实现 | `linhub\host\services\inbox\InboxService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['inbox'], ...]

$inbox = HostServiceApi::getService($this->app, 'inbox', 'demo');

// 向指定用户投递
$inbox->sendToUsers('demo', ['title' => '审批通过', 'link' => '/admin/demo/detail?id=1'], ['u_1001']);
```

---

## 四、契约方法速查

### 发送侧

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `sendToUsers` | `sendToUsers(string $sourcePackage, array $message, array $userIds, array $meta = []): array` | 按用户投递；`$message` 为 `{title, content?, link?}`；返回 `{msg_key, recipient_count}` |
| `sendToRoles` | `sendToRoles(string $sourcePackage, array $message, array $roleIds, array $meta = []): array` | 按角色投递（发送时解析快照） |
| `sendToGroups` | `sendToGroups(string $sourcePackage, array $message, array $groupIds, array $meta = []): array` | 按用户组（权限组）投递（发送时解析快照） |
| `send` | `send(string $sourcePackage, array $message, array $targets, array $meta = []): array` | 混合投递：`$targets` 为 `{users?, roles?, groups?}`，解析后去重落一条消息 |

`$meta` 可选：`sender_id` / `sender_name`（发送方留痕）。

### 收件侧

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `unreadCount` | `unreadCount(Identity $viewer): int` | 当前用户未读数（红点） |
| `inbox` | `inbox(Identity $viewer, string $filter = 'all', int $page = 1, int $size = 20): array` | 收件箱分页；`$filter`：`all\|unread\|read` |
| `markRead` | `markRead(Identity $viewer, string $msgKey): void` | 标记已读（幂等） |
| `markAllRead` | `markAllRead(Identity $viewer): int` | 全部未读置已读，返回受影响条数 |

### 管理端

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `paginateMessages` | `paginateMessages(array $filter, int $page = 1, int $size = 20): array` | 投递记录分页；filter 支持 `source_pkg`/`keyword`/`target_type`/`user_id`/`is_read`/`date_from`/`date_to` |
| `messageDetail` | `messageDetail(string $msgKey): ?array` | 单条消息主体 + 接收人解析快照 |
| `stats` | `stats(): array` | 平台用量汇总（看板用） |

目标类型常量：`TARGET_USER` / `TARGET_ROLE` / `TARGET_GROUP` / `TARGET_MIXED`。

---

## 五、投递与收件用法

- 投递时把**角色、用户组解析为具体用户并落收件快照（去重）**——后续角色成员变化不影响已投递消息。
- 收件侧供外框架通知中心与管理端投递记录页消费；功能包页面可用 `unreadCount` + `inbox` 自建通知入口。

---

## 六、使用要点与避坑

1. `$sourcePackage` 传本包短名，便于投递记录溯源与管理端过滤。
2. `link` 建议填平台内可访问的完整路径（含 `admin/` 前缀），点击消息可直达。
3. 目标量大时优先按角色/用户组投递，避免拼大数组用户列表。
4. `unreadCount`/`inbox` 需要 `Identity $viewer`（当前登录身份），未登录场景自行拦截。
5. 消息是"通知"不是"业务数据"——业务状态以库表为准，消息仅作提醒。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
