# LinHub Host 平台服务开发契约、规则与规范

- 文档创建时间：2026-10-20 00:00:00
- 最后修改时间：2026-10-24 11:00:00
- 当前版本：V_1.2
- 适用对象：宿主工程（host）开发者、功能包开发者、平台内核维护者
- 核心目标：明确平台服务的定位、契约接口、接入方式与规则红线，保证宿主与功能包之间基于契约解耦、版本可控、安全可控。
- 文档简略描述：本文档是 `code/host/linhub/host` 目录的 README，聚焦平台服务的开发契约、规则与规范。以 `src/services/services.php` 为唯一权威服务清单，逐一说明接入链、宿主只读铁律、版本约束、caller 焊死等机制，并提供各服务的契约接口签名。

---

## 一、文档大纲

1. [平台服务定位](#1-平台服务定位)
2. [核心规则（红线）](#2-核心规则红线)
3. [服务清单（唯一权威来源）](#3-服务清单唯一权威来源)
4. [接入链与调用方式](#4-接入链与调用方式)
5. [各服务契约说明](#5-各服务契约说明)
6. [公共约定与避坑](#6-公共约定与避坑)

---

## 1. 平台服务定位

平台服务是宿主工程（host）面向功能包暴露的基础能力，遵循 **"契约接口 + 实现类"** 分离原则：

- 功能包 **只面向契约接口**编程，不感知实现细节；
- 宿主通过注册表把"服务 id → 契约接口 → 实现类"登记起来；
- 功能包在 `manifest.services.requires` 中声明依赖，运行时由注入器解析并注入。

平台服务的完整代码位置：`src/services/`（契约接口 + 实现 + 服务清单）。

---

## 2. 核心规则（红线）

| 编号 | 规则 | 说明 |
| --- | --- | --- |
| M6 | `services.php` 是权威清单 | 服务清单唯一权威来源为 `src/services/services.php`。新增/修改平台服务，**必须**同步 `list` 与 `changelog`，否则注册表/版本约束判定会失效。 |
| M12 | 宿主服务只读 | `source='host'` 的服务，功能包**只能消费，不可注册、不可覆盖、禁止写入**。`ServiceRegistry` 在 `registerPackage()` 中禁止覆盖 host 源。 |
| N3/M | package 源可注册 | `source='package'` 的服务由功能包登记，需经注册表校验存在性后才能注入。 |
| M006 | 注入前校验 | `ServiceInjector::resolveAll()` 会校验 require 的服务是否存在且版本满足约束，不满足则终止对接。 |
| —— | caller 焊死 | 对 `llmgateway` 服务，注入器会包裹 `BoundLlmGateway` 装饰器，把 `caller` 焊死为 `pkg.<pkgId>`，功能包显式传 caller 也会被覆盖。 |
| —— | 仅接口服务不可注入 | `filestore` / `scheduler` 目前只有契约接口、`impl=null`，功能包**无法注入使用**，仅作为契约占位。 |

---

## 3. 服务清单（唯一权威来源）

服务共 **14** 个，全部 `version = 1.0.0`、`source = 'host'`。

| 服务 id | 契约接口 | 实现类 | 状态 | changelog 时间 |
| --- | --- | --- | --- | --- |
| logging | `Logging` | `LoggingService` | ✅ 已实现 | 2026-10-09 |
| config | `Config` | `ConfigService` | ✅ 已实现 | 2026-10-09 |
| cache | `Cache` | `CacheService` | ✅ 已实现 | 2026-10-09 |
| id | `IdGenerator` | `SequentialIdGenerator` | ✅ 已实现 | 2026-10-09 |
| filestore | `FileStore` | —（null） | ⚠️ 仅接口 | 2026-10-09 |
| messaging | `Messaging` | `MessagingService` | ✅ 已实现 | 2026-10-21 |
| scheduler | `TaskScheduler` | —（null） | ⚠️ 仅接口 | 2026-10-09 |
| attachment | `Attachment` | `AttachmentService` | ✅ 已实现 | 2026-10-11 |
| dictionary | `Dictionary` | `DictionaryService` | ✅ 已实现 | 2026-10-11 |
| loginstate | `LoginState` | `LoginStateService` | ✅ 已实现 | 2026-10-11 |
| inbox | `Inbox` | `InboxService` | ✅ 已实现 | 2026-10-11 |
| llmgateway | `LlmGateway` | `LlmGatewayService` | ✅ 已实现 | 2026-10-19 |
| package | `PackageProbe` | `PackageProbeService` | ✅ 已实现 | 2026-10-19 |
| auth | `RbacQuery` | `RbacQueryService` | ✅ 已实现 | 2026-10-20 |

> 实际以 `src/services/services.php` 的 `list` 为准。版本约束语义（`^` / `~` / `>=` / `<` / 逗号 AND）由 `VersionConstraint` 统一实现。

---

## 4. 接入链与调用方式

### 4.1 三层接入链

```
功能包代码
   └─ HostServiceApi（门面：ensureRegistered / getService / inject）
        └─ ServiceInjector（resolveAll：校验服务存在 + 版本，llmgateway 焊死 caller）
             └─ 面向契约接口消费（ServiceRegistry.resolve 返回值）
```

### 4.2 功能包侧用法

功能包在 `manifest` 中声明：

```php
// manifest.services.requires —— 依赖的宿主服务 id（可带版本约束）
'requires' => [
    'id',                 // 裸 id
    'config' => '^1.0.0', // id => constraint
],

// manifest.services.provides —— 本包托管的服务
'provides' => [
    ['id' => 'xxx', 'class' => \Pkg\Xxx::class, 'version' => '1.0.0'],
],
```

运行时注入（经由 `HostServiceApi`，参考过往接口 `getService(App, id, ?pkgId)` / `inject(App, requires, ?pkgId)` / `ensureRegistered()`）：

```php
// 伪代码示意，实际走 HostServiceApi 门面
$config = $api->getService($app, 'config', $pkgId);   // 面向 Config 契约消费
$requires = $api->inject($app, ['config' => '^1.0.0'], $pkgId);
```

> 📌 call `llmgateway` 时无需也不能手动指定 caller，注入器会通过 `BoundLlmGateway` 焊死。

---

## 5. 各服务契约说明

### 5.1 logging — 日志

- 契约：`Logging`
- 方法：`info(string $message, array $context = [])` / `warning` / `error` / `debug`
- 语义：向功能包暴露写日志能力。

### 5.2 config — 配置（只读）

- 契约：`Config`
- 方法：`get(string $key, $default)` / `has(string $key)` / `package(string $packageShort)`
- 语义：只读，支持按包读取。

### 5.3 cache — 缓存

- 契约：`Cache`
- 方法：`get` / `set` / `has` / `delete`
- 语义：`set` 的 ttl 单位为秒；`ttl <= 0` 表示不过期。

### 5.4 id — 主键生成

- 契约：`IdGenerator`
- 方法：`generate(): string` / `generateInt(): int`
- 语义：序列化 ID。

### 5.5 filestore — 文件存储（⚠️ 仅接口，不可注入）

- 契约：`FileStore`
- 方法：`put` / `get`（不存在抛 `InvalidArgumentException`）/ `exists` / `delete`
- 语义：路径按包短名隔离。

### 5.6 messaging — 消息/事件（✅ 已实现，可注入）

- 契约：`Messaging`（实现 `MessagingService`，共享进程内事件总线 Broker，见 `communication/broker/`）
- 方法：`publish(topic, payload)` / `subscribe(topic, handler)`
- 静态装配入口：`MessagingService::broadcastLifecycle()`（宿主生命周期广播）/ `subscribeFromManifests()`（汇总 manifest.subscribes）
- 主题规则（M008）：`lg.<short>.`（本方）、`lg.<otherShort>.`（跨包）、`lg.platform.`（宿主保留）三类合法；坏形状主题安装被拦。
- handler 契约：无参构造 + `handle(Event $event)`（`Event` 含 `topic/payload/occurredAt/source/id`）；不支持 `Class@method`。
- 装配机制：宿主 `PackageRouteLoader` 每请求汇总已启用包 `subscribes` 注册进共享总线；功能包亦可 `boot()` 主动 `subscribe`（幂等互补）。
- **宿主生命周期广播**：安装/升级/卸载/打包成功后广播 `lg.platform.package.<installed|upgraded|removed|packed>`（载荷含 `short`、版本号等），投递失败只记不抛、不影响主流程。

### 5.7 scheduler — 任务调度（⚠️ 仅接口，不可注入）

- 契约：`TaskScheduler`
- 方法：`schedule(id, cron, handler)` / `cancel(id)`
- 语义：cron 表达式调度。

### 5.8 loginstate — 登录态

- 契约：`LoginState`
- 方法：`snapshot()`（含 `is_login` / `guest` / `user_id` / `username` / `display_name` / `is_super_admin` / `tags` / `roles`）/ `isLogin()` / `userId()` / `tags()`
- 语义：未登录返回游客态，**不抛异常**。

### 5.9 attachment — 附件

- 契约：`Attachment`
- 能力：`store($ownerPackage, $content, $originName, $meta = [])` / `fetch($token, ?Identity $viewer)`（抛 `AttachmentDenied` / `AttachmentNotFound`）/ `delete` / `findByToken` / `url` / `paginate` / `stats` / `configState` / `testDriver` / `setFallbackImage` / `resetFallbackImage` / `fallbackImage` / `fallbackToken` / `saveSettings` / `clean` / `cleanRecords`

### 5.10 dictionary — 字典

- 契约：`Dictionary`
- 能力：`put` / `get` / `remove` / `search` / `entry` / `stats`
- 语义：绑定包身份；本包可写、他包公开可读；超管 `$asAdmin` 可跨包。

### 5.11 inbox — 站内信

- 契约：`Inbox`
- 常量：`TARGET_USER` / `TARGET_ROLE` / `TARGET_GROUP` / `TARGET_MIXED`
- 能力：`sendToUsers` / `sendToRoles` / `sendToGroups` / `send` / `unreadCount(Identity)` / `inbox(Identity, $filter='all', $page=1, $size=20)` / `markRead` / `markAllRead` / `paginateMessages` / `messageDetail` / `stats`

### 5.12 llmgateway — LLM 网关

- 契约：`LlmGateway`
- 能力：`chat(ChatRequest)` / `chatStream(ChatRequest): \Generator` / `embed(EmbedRequest)` / `listModels(?$capability)` / `getModel($code)`
- 异常：`LlmModelUnavailableException` / `LlmQuotaExceededException` / `LlmProviderException` / `InvalidArgumentException`
- 实现要点：env 注入 `LLM_PROVIDER_<NAME>_BASE_URL` / `_API_KEY` / `_TIMEOUT_CONNECT`（默认 5）/ `_TIMEOUT_READ`（默认 60），开关 `LLM_GATEWAY_ENABLED`（默认 1）。编排：解析 caller → 读 `lg_mgt_llm_model` → 校验启用+配额 → 构造 client → 调用 → 写审计日志。
- caller 规则：经 `BoundLlmGateway` 焊死为 `pkg.<pkgId>`（空则为 `pkg.unknown`），功能包无法覆盖。

**DTO：**
- `ChatRequest`：字段含 `model` / `messages` / `temperature` / `max_tokens` / `extra` / `caller`（空白可注入，注入后焊死不覆盖）；构造校验 `model`、`messages` 非空、`messages` 含 `role+content`；`withCaller()`。
- `ChatResponse`：`content` / `finish_reason` / `usage` / `model` / `call_id` + `toArray()`。
- `EmbedRequest`：`model` / `input`(string|array) / `caller` + `inputList()`。
- `EmbedResponse`：`vectors` / `usage` / `model` / `call_id` + `toArray()`。

### 5.13 package — 功能包探针

- 契约：`PackageProbe`
- 能力：`isInstalled` / `isEnabled` / `versionOf` / `snapshot` / `listInstalled`
- 语义：全部**不抛异常**；状态语义为 `ABSENT` / `ENABLED` / `DISABLED` / `DELETED`。

### 5.14 auth — RBAC 查询（只读）

- 契约：`RbacQuery`
- 能力（17 项）：`currentSnapshot` / `currentUserId` / `currentPermCodes` / `currentRoleIds` / `currentGroupIds` / `userById` / `userByRefId` / `listUsers` / `permCodesOf` / `listRoles` / `roleById` / `userIdsOfRole` / `listGroups` / `groupById` / `listPermissions` / `hasPermission` / `userHasPermission`
- 语义：只读、吞异常、收敛字段。

---

## 6. 公共约定与避坑

1. **读写边界**：`config` / `auth` / `loginstate` 为**只读**；`logging` 为写日志；其余按各服务语义。
2. **异常约定**：`attachment` 明确抛领域异常；`package` 探针全部吞异常；`loginstate` 游客态不抛异常；`filestore` 找不到抛 `InvalidArgumentException`；`llmgateway` 抛专用异常。调用方应按各服务文档处理。
3. **不可注入服务**：`filestore` / `scheduler` 在服务清单中有版本（`1.0.0`），能通过"存在+版本"校验（M006），但 `impl=null`，**注入实例时**会抛 `RuntimeException("暂无宿主内置实现，无法注入服务")`。因此不建议在 requires 中声明。
4. **host 只读**：功能包**不得**注册或覆盖任何 host 源服务，违反会触发 `registerPackage()` 拦截。
5. **版本约束**：requires 声明尽量带约束（如 `'config' => '^1.0.0'`），避免裸 id 带来的隐式升级。
6. **llmgateway caller**：功能包调用 LLM，身份由注入器自动焊死为包身份，**不要手动传 caller**，审计入账均以焊死值为准。
7. **权威清单**：改服务先改 `src/services/services.php` 的 `list` 与 `changelog`，再改实现，避免清单与实现脱节。

---

## 版本历史

- **V_1.2**（2026-10-24）：关联文档同步 08 总路由改造——平台服务接入方式指引至 08 总路由与 `coding-guide/services/` 各服务子路由。
- **V_1.0**（2026-10-20）：首次创建。确立平台服务定位、核心规则（M6/M12/M006/caller 焊死）、14 服务清单、三层接入链、各服务契约接口签名、公共约定与避坑。

---

## 关联文档

- 平台服务接入方式补充见 `coding-guide/08_平台服务接入指引.md`（总路由；各服务接入方法见其 `coding-guide/services/<id>.md` 子路由）
- 后端编码规范见 `coding-guide/02_后端编码指引.md`
- 数据与迁移（单库架构）见 `coding-guide/05_数据与迁移指引.md`