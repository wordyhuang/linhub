# LinHub 服务接入指南 · loginstate 登录状态服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 loginstate 登录状态服务的契约方法与只读快照用法。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 loginstate 一个服务；屏蔽底层 session/UAIS 解析细节。

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
| 服务 id | `loginstate` |
| 契约接口 | `linhub\host\services\loginstate\LoginState` |
| 宿主实现 | `linhub\host\services\loginstate\LoginStateService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['loginstate'], ...]

$ls = HostServiceApi::getService($this->app, 'loginstate', 'demo');

if ($ls->isLogin()) {
    $uid = $ls->userId();
    $snap = $ls->snapshot();
}
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `snapshot` | `snapshot(): array` | 登录态快照：`{is_login, guest, user_id, username, display_name, is_super_admin, tags, roles}` |
| `isLogin` | `isLogin(): bool` | 当前是否已登录 |
| `userId` | `userId(): string` | 当前用户 ID；游客返回空串 |
| `tags` | `tags(): array` | 当前身份标签 tags |

---

## 五、使用要点与避坑

1. **只读统一出口**：功能包做身份判断用它，**不要**直接读 Session、不要自己解析 UAIS 身份。
2. 未登录返回游客态结构，**不抛异常**；但仍建议先 `isLogin()` 分支。
3. 需要权限点/角色明细判断时用 **auth** 服务（`currentPermCodes()` 等），loginstate 只给快照概要。
4. 快照字段是收敛后的必要项，不要期望拿 token/会话内部态。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
