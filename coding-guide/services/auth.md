# LinHub 服务接入指南 · auth 账号与权限服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 auth 账号与权限服务（RbacQuery）的契约方法、ensureRole 接入模式与只读边界。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 auth 一个服务；V1.1 起含 ensureRole 写方法（幂等接入角色）。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. ensureRole：功能包接入角色
5. 使用要点与避坑
6. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `auth` |
| 契约接口 | `linhub\host\services\auth\RbacQuery` |
| 宿主实现 | `linhub\host\services\auth\RbacQueryService` |
| 版本 | 1.1.0（V1.1 新增 ensureRole） |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['auth'], ...]

$rbac = HostServiceApi::getService($this->app, 'auth', 'demo');

$snap  = $rbac->currentSnapshot();          // 当前登录快照
$perms = $rbac->currentPermCodes();         // 当前用户最终权限点
$ok    = $rbac->userHasPermission($uid, 'demo:demo:manage');
```

---

## 四、契约方法速查

### 当前登录态

| 方法 | 说明 |
| --- | --- |
| `currentSnapshot(): array` | 快照：`{is_login, guest, user_id, username, display_name, is_super_admin, tags, roles}` |
| `currentUserId(): string` | 当前用户本地 id（游客空串） |
| `currentPermCodes(): array` | 当前用户最终权限点 code 列表 |
| `currentRoleIds(): array` | 当前用户所属角色 id 列表 |
| `currentGroupIds(): array` | 当前用户所属权限组 id 列表 |

### 用户查询

| 方法 | 说明 |
| --- | --- |
| `userById(string $userId): array` | 按本地 id 查档案 `{id, name, refId, lastLoginAt, roles, groups, permCodes}` |
| `userByRefId(string $refId): array` | 按 UAIS refId 查（未建档返回空） |
| `listUsers(string $keyword = ''): array` | 用户列表（姓名/refId 不区分大小写） |
| `permCodesOf(string $userId): array` | 指定用户最终权限点 |

### 角色 / 权限组 / 权限点

| 方法 | 说明 |
| --- | --- |
| `listRoles(): array` | 角色列表 `{id, name, type, typeName, readonly, userCount}` |
| `roleById(string $roleId): array` | 按角色 id 查档案 |
| `userIdsOfRole(string $roleId): array` | 角色下用户 id 列表 |
| `listGroups(): array` | 权限组列表 `{id, name, permCodes}` |
| `groupById(string $groupId): array` | 按权限组 id 查档案 |
| `listPermissions(): array` | 权限点清单（菜单/按钮可见性判断用） |
| `hasPermission(string $code): bool` | 权限点是否存在 |
| `userHasPermission(string $userId, string $code): bool` | 用户是否拥有指定权限点 |

---

## 五、ensureRole：功能包接入角色

```php
$res = $rbac->ensureRole('演示包管理员', 'FUNCTIONAL_ADMIN');
// {created:bool, id:string, name:string, type:string, error:string}
```

- **幂等**：按角色名判重——无则创建（created=true），有则跳过（created=false 且 id 非空）。
- 类型白名单：`WORKER` / `FUNCTIONAL_ADMIN` / `SYSTEM_ADMIN`；**禁止自建 SUPER_ADMIN**。
- 写失败如实返回 `error`（非法类型/名称非法/底层异常），id 为空——调用方必须检查 `error`。

---

## 六、使用要点与避坑

1. **只读为主**：除 `ensureRole` 外全部只读，底层异常吞掉返回空结构——功能包侧永不抛。
2. 鉴权/菜单可见性统一用 auth 服务，**不要**直连 `linhub\host\rbac\*` 内核类、不要读 registry JSON。
3. 做当前用户权限判断用 `currentPermCodes()`（一次拿全）而非循环 `userHasPermission()`。
4. `ensureRole` 适合在包安装/初始化流程调用（幂等可重复执行）；不要在每次请求里调。
5. 与 loginstate 的分工：loginstate 给登录快照概要；auth 给权限/角色明细与用户档案查询。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
