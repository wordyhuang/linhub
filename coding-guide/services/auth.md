# LinHub 服务接入指南 · auth 账号与权限服务 V1.2

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-10 14:30:00
> **当前版本**：V_1.2
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 auth 账号与权限服务（RbacQuery）的契约方法、预设角色清单与只读边界。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 auth 一个服务；V1.2 起服务全只读——系统仅提供 5 个预设角色、不可增删改，V1.1 的 ensureRole 写方法已移除。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 预设角色与权限项 roles 白名单
5. 数据随包导出与导入（manifest 配置）
6. 使用要点与避坑
7. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `auth` |
| 服务类型 | 功能服务（functional） |
| 数据随包 | 名义支持（seed / all），V1.2 起导出恒为空集（见「数据随包导出与导入」节） |
| 契约接口 | `linhub\host\services\auth\RbacQuery` |
| 宿主实现 | `linhub\host\services\auth\RbacQueryService` |
| 版本 | 1.2.0（V1.2 移除 ensureRole，全只读） |
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
| `currentSnapshot(): array` | 快照：`{is_login, guest, user_id, username, display_name, is_super_admin, tags, roles}`；`roles` 为预设角色英文键列表 |
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
| `listRoles(): array` | 角色列表（仅 5 个预设角色）`{id, name, readonly, userCount}` |
| `roleById(string $roleId): array` | 按角色 id 查档案（同上结构，不存在返回 id 空） |
| `userIdsOfRole(string $roleId): array` | 角色下用户 id 列表 |
| `listGroups(): array` | 权限组列表 `{id, name, permCodes}` |
| `groupById(string $groupId): array` | 按权限组 id 查档案 |
| `listPermissions(): array` | 权限点清单（菜单/按钮可见性判断用） |
| `hasPermission(string $code): bool` | 权限点是否存在 |
| `userHasPermission(string $userId, string $code): bool` | 用户是否拥有指定权限点 |

---

## 五、预设角色与权限项 roles 白名单

### 5.1 系统预设角色（仅此 5 个，不可新增/改名/删除）

| 角色 id（固定） | 英文键 | 中文名 | 说明 |
| --- | --- | --- | --- |
| `role_worker` | `WORKER` | 工作者 | 普通业务用户，兜底角色 |
| `role_reviewer` | `REVIEWER` | 审核员 | 审核/把关类用户 |
| `role_functional_admin` | `FUNCTIONAL_ADMIN` | 功能管理员 | 业务功能管理员 |
| `role_system_admin` | `SYSTEM_ADMIN` | 系统管理员 | 系统运维管理员 |
| `role_super_admin` | `SUPER_ADMIN` | 超级管理员 | 平台占位、只读 |

- 角色记录不再有 `type` 字段（V1.2 取消角色类型维度）；角色 id 固定，`readonly=true`。
- 首页落地优先级：超管 > 系统管理员 > 功能管理员 > 审核员 > 工作者；审核员复用工作者首页。

### 5.2 权限项 roles 取值口径

permissions.php（及权限组针对角色）的 `roles` 字段：

- **只能**取上表 5 个英文键常量（可多个），如 `['roles' => ['FUNCTIONAL_ADMIN', 'SYSTEM_ADMIN']]`；
- **或留空**（省略 / `[]`）= 不限角色，对所有角色可见；
- 不得出现其他取值（自定义角色已不存在，未知键不参与匹配）。

---

## 六、数据随包导出与导入（manifest 配置）

V1.2 起角色全部为平台预设定义，**角色定义不再随功能包导出/导入**：

- manifest 仍可声明 `'auth' => ['scope' => 'seed']`（兼容既有配置），但 `seed` / `all` 导出恒为空集；
- 导入仅接受 5 个预设角色 id 的绑定信息且**不创建任何角色**，非预设 id 记告警并跳过；
- 卸载清理是**空操作**——角色是平台公共数据、无包归属，purge 恒返回 0。

---

## 七、使用要点与避坑

1. **全只读**：所有方法只读，底层异常吞掉返回空结构——功能包侧永不抛；不要再调 `ensureRole`（已移除，调用会报方法不存在）。
2. 鉴权/菜单可见性统一用 auth 服务，**不要**直连 `linhub\host\rbac\*` 内核类、不要读 registry JSON。
3. 做当前用户权限判断用 `currentPermCodes()`（一次拿全）而非循环 `userHasPermission()`。
4. 需要角色语义时用英文键常量（`WORKER` 等）比对 `currentSnapshot()['roles']`，不要比对中文角色名。
5. 与 loginstate 的分工：loginstate 给登录快照概要；auth 给权限/角色明细与用户档案查询。

---

## 八、版本变化记录

- **V1.2（2026-11-10）**：角色模型收缩——系统仅 5 个预设角色（新增「审核员」REVIEWER），取消角色类型维度，角色不可增删改；移除 V1.1 的 `ensureRole` 写方法，服务转为全只读；`listRoles/roleById` 返回去掉 `type/typeName`；数据随包导出恒为空集、导入只认预设角色 id；明确权限项 roles 只能落 5 个预设角色英文键或为空。
- **V1.1（2026-11-03）**：新增 `ensureRole` 写方法（幂等接入角色）。
- **V1.0（2026-10-24）**：初版，只读查询服务。
