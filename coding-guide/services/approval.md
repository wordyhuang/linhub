# LinHub 服务接入指南 · approval 审批服务 V1.0

> **文档创建时间**：2026-10-28 22:30:00
> **最后修改时间**：2026-10-28 22:30:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 approval 审批服务的契约方法、DSL 写法、状态机与包间隔离规则。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 approval 一个服务；功能包用 DSL 定义审批流程模板，提交单据后由平台引擎推进（串行或签/会签、排他网关、并行网关 + 汇合），到终态发平台事件并给提交人发站内信；包卸载时在途单据冻结为孤儿态，重装同名包自动接管。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 流程 DSL 与校验规则
5. 核心语义（双快照 / 状态机 / 网关与汇合 / 整单驳回 / 事件与站内信）
6. 包间隔离与生命周期
7. 使用要点与避坑
8. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `approval` |
| 契约接口 | `linhub\host\services\approval\Approval` |
| 宿主实现 | `linhub\host\services\approval\ApprovalService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |
| 数据表（前缀 `lg_mgt_`） | `lg_mgt_appr_flow` / `lg_mgt_appr_instance` / `lg_mgt_appr_node_instance` / `lg_mgt_appr_action` |

异常类（均在 `services\approval\` 下，继承 `\RuntimeException`）：

| 异常 | 触发场景 |
| --- | --- |
| `ApprovalFlowInvalid` | DSL 校验失败、`flow_key` 未注册/已停用 |
| `ApprovalState` | payload 超 1MB、options 非法、对终态/孤儿单据操作、驳回意见为空 |
| `ApprovalDenied` | 审批人不在当前节点快照内、跨包访问、非提交人撤回 |
| `ApprovalResolverError` | 自定义解析器执行失败（节点标 `error`，单据保持 pending） |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['approval'], ...]

$ap = HostServiceApi::getService($this->app, 'approval', 'demo');

// 1. 定义（或覆盖更新）流程模板，version 自增
$ap->defineFlow(null, [
    'flow_key' => 'purchase',
    'name'     => '采购申请',
    'entry'    => 'n1',
    'nodes'    => [
        ['id' => 'n1', 'type' => 'approval', 'name' => '部门经理审批',
         'approvers' => ['users' => ['1001'], 'roles' => ['dept_manager']],
         'mode' => 'any', 'next' => 'gw1'],
        ['id' => 'gw1', 'type' => 'exclusive', 'name' => '金额路由',
         'branches' => [
             ['condition' => ['field' => 'payload.amount', 'op' => 'gte', 'value' => 5000], 'next' => 'n_hi'],
             ['condition' => null, 'next' => 'n_lo'],
         ]],
        ['id' => 'n_hi', 'type' => 'approval', 'name' => '财务总监审批',
         'approvers' => ['roles' => ['finance_director']], 'mode' => 'all', 'next' => null],
        ['id' => 'n_lo', 'type' => 'approval', 'name' => '财务复核',
         'approvers' => ['roles' => ['finance']], 'mode' => 'any', 'next' => null],
    ],
]);

// 2. 提交：payload 快照落库，首节点激活并给审批人发站内信
$no = $ap->submit(null, 'purchase', ['amount' => 6800, 'reason' => '服务器扩容'], [
    'title'         => '采购申请-服务器扩容',
    'summary'       => '金额 6800 元',
    'submitterId'   => '1001',
    'submitterName' => '张三',
]);

// 3. 审批（双入口：审批中心控制器 / 包自建 UI）
$approver = new \linhub\capability\auth\Identity('2001', '李经理', []);
$ap->approve($approver, $no, '同意');
$ap->reject($approver, $no, '预算不足');   // 意见必填 → 整单驳回

// 4. 查询 / 待办
$detail = $ap->get(null, $no);
$page   = $ap->list(null, ['status' => 'pending', 'keyword' => '采购'], 1, 20);
$todo   = $ap->myPending($approver, 1, 20);
```

---

## 四、契约方法速查

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `defineFlow(?string $package, array $flow)` | `array` | 定义/覆盖流程模板，`version` 自增；校验失败抛 `ApprovalFlowInvalid` |
| `submit(?string $package, string $flowKey, array $payload, array $options = [])` | `string` | 提交单据，返回 `approval_no`（`APR` + 32 位 hex）；options：`title` 必填，`summary`/`submitterId`/`submitterName` 可选 |
| `get(?string $package, string $approvalNo)` | `?array` | 单据 + 节点实例链 + 动作时间线；不存在返回 `null` |
| `list(?string $package, array $filter, int $page = 1, int $size = 20)` | `array` | 分页检索；filter 下划线键：`status`/`flow_key`/`submitter_id`/`keyword`/`date_from`/`date_to`（`package` 仅 host 跨包全量时生效） |
| `cancel(?string $package, string $approvalNo, string $comment = '')` | `bool` | 撤回（仅 pending）；终态抛 `ApprovalState` |
| `approve(Identity $approver, string $approvalNo, string $comment = '')` | `bool` | 同意；越权抛 `ApprovalDenied`；重复提交幂等返回 `true` |
| `reject(Identity $approver, string $approvalNo, string $comment)` | `bool` | 驳回 → 整单 `rejected`；意见为空抛 `ApprovalState` |
| `myPending(Identity $viewer, int $page = 1, int $size = 20)` | `array` | 我的待办（`status=active` 且 viewer 在快照内） |
| `registerResolver(string $name, string $handlerClass)` | `void` | 注册自定义审批人解析器（同名覆盖） |
| `stats()` | `array` | 用量统计：总数 / 在办 / 按状态 / 按包聚合 |

平台审批中心额外使用的实现侧扩展方法（功能包无需调用）：

| 方法 | 说明 |
| --- | --- |
| `cancelAs(?Identity $actor, ?string $package, string $no, string $comment)` | 代操作人撤回，写操作人信息 |
| `myHandled(Identity $viewer, int $page, int $size)` | 我已处理（有动作记录的单据） |
| `canAct(Identity $viewer, string $no)` | 当前用户是否可审批该单据 |
| `orphanPackage(string $short)` / `adoptPackage(string $short)` | 卸载冻结 / 重装接管（生命周期自动调用） |

---

## 五、流程 DSL 与校验规则

节点类型：`approval`（审批）/ `exclusive`（排他网关）/ `parallel`（并行网关）。

- **approval**：`approvers{users[],roles[],resolver}` 至少一个来源（混合时合并去重）；`mode` = `any`（或签，任一人同意即过）/ `all`（会签，全员同意才过）；`next` 为下一节点 id，`null` 表示终点。
- **exclusive**：`branches[]` 每支 `{condition, next}`；必须有且仅有一个 `condition = null` 的默认分支；运行时按声明顺序取首个命中。
- **parallel**：`branches[]`（无 condition，`{next}`）至少 2 支；`join` 为汇合节点 id（必须存在且非网关自身）；各分支终点写虚拟汇合标识 `__join_<网关id>__`。

条件 `condition`：`{field, op, value}`

- `field` 支持 `payload.*`（提交内容快照）与 `submitter.*`（发起人上下文）；
- `op` ∈ `eq/ne/gt/gte/lt/lte/in/not_in/contains/exists`；
- 可嵌套 `{all:[...]}` / `{any:[...]}`，嵌套深度 ≤ 3。

校验（`defineFlow` 阶段一次性拦截，抛 `ApprovalFlowInvalid`）：

1. `flow_key` 必填且包内唯一；`entry` 必须存在；
2. 所有 `next`/`branches[].next`/`join` 引用必须存在，虚拟汇合标识只能指向已声明的并行网关；
3. 排他网关默认分支数量必须恰好 1；并行网关分支数 ≥ 2；
4. 分支必须能到达其汇合点（并行内不允许死路）；
5. 节点链不得成环（DFS 三色标记检测）；
6. approval 节点 `approvers` 不得为空，`mode` ∈ `any/all`。

> 流程模板版本化：同名 `flow_key` 覆盖更新时 `version` 自增；在途单据沿用提交时快照的定义语义，不受后续改版影响。

---

## 六、核心语义

### 6.1 双快照

- **payload 快照**：`submit` 时把业务内容整体快照落库，之后包侧源数据变动不影响在途单据；
- **审批人快照**：节点**激活时**才解析——`users` 直接取用、`roles` 经 RBAC 解析为当前用户、`resolver` 调用注册的解析器；合并去重后落库。快照后角色变动不影响在途节点。

### 6.2 状态机

- 单据：`pending` → `approved` / `rejected` / `cancelled`（终态）；包卸载 → 仍是 `pending` 但 `orphaned=1` 冻结；
- 节点：`waiting` → `active` → `approved` / `rejected` / `skipped` / `error`；
- 单据完成判定集中在收尾逻辑：无 `active/waiting/error` 节点且至少有一个审批节点通过 → `approved`（避免并行分支提前误判完成）。

### 6.3 网关与汇合

- 排他网关：按声明顺序取首个命中分支推进，无命中走默认分支；
- 并行网关：各分支首节点同时 `active`；每支到达 `__join_<网关id>__` 时记一条汇合标记（按分支去重计数），全部分支到齐后才激活 `join` 后继节点，`branch_path` 同步剔除所属网关上下文；
- 或签（any）任一人同意即过；会签（all）按快照人数累计至全员同意。

### 6.4 整单驳回

任一人 `reject` → 单据立即 `rejected`，全部 `active/waiting` 节点终止（`skipped`），发布 `completed` 事件并给提交人发站内信；驳回意见必填。

### 6.5 事件与站内信

| 事件主题 | 载荷 |
| --- | --- |
| `lg.platform.approval.submitted` | `approval_no` / `package` / `flow_key` / `title` / `submitter_id` / `first_nodes` |
| `lg.platform.approval.completed` | `approval_no` / `package` / `status` / `finished_at` |

站内信（服务内部自动，无需包干预）：节点激活 → 通知快照内全部审批人；单据到终态 → 通知提交人。通知在 DB 事务 **commit 之后** 发送，单条失败只记日志，不阻断主流程。

---

## 七、包间隔离与生命周期

- `$package = null` 时按调用链静态回溯判定 caller 包（与 recyclebin 一致）；显式传包名时须与单据归属一致，否则抛 `ApprovalDenied`；
- 包的流程模板、单据、节点、动作全部按包隔离；跨包读与写同一规则；
- `host` 保留命名空间可跨包全量读取（审批中心「全部单据」），按 `filter['package']` 过滤；
- 包卸载：在途单据 `orphaned=1` 冻结，`approve/reject/cancel` 对孤儿单据抛 `ApprovalState`；
- 重装同名包：`adoptPackage` 自动接管，按当前流程定义重解析 `active/error` 节点审批人快照，恢复推进。

---

## 八、使用要点与避坑

1. **必读双快照语义**：审批人快照在节点激活时冻结，不要期望运行中改角色能改变在途节点。
2. **并行汇合写虚拟标识**：分支终点必须写 `__join_<网关id>__`，不要直接写 `join` 节点 id，否则汇合计数不生效。
3. **排他网关必须有默认分支**：缺失或出现多个 `condition=null` 都会被 `defineFlow` 拒绝。
4. **payload ≤ 1MB**：超限抛 `ApprovalState`，大对象请存业务表后用引用字段进入审批。
5. **解析器必须无参构造**：实现 `resolve(array $context): array` 返回用户 id 列表；执行失败节点标 `error`、单据停在 `pending`，并抛 `ApprovalResolverError`（消息含 `approval_no`，数据已落库不会丢）。
6. **双入口身份一致**：平台审批中心由 admin 控制器构造 `Identity`；包自建 UI 经 `loginstate` 服务取当前用户构造 `Identity`，服务只认快照匹配，不认调用来源。
7. **撤回不是删除**：`cancel` 只终止流程（终态 `cancelled`），业务数据回收请配合 recyclebin 服务。
8. **不要绕过服务写表**：四张 `lg_mgt_appr_*` 表归平台引擎所有，包侧直接写会破坏状态机与事件一致性。

---

## 九、版本变化记录

- **V1.0（2026-10-28）**：初版。approval 审批服务 1.0.0 上线：DSL 流程模板（串行 any/all、排他网关、并行网关 + 汇合）、双快照、整单驳回、事件与站内信、自定义解析器、孤儿态与重装接管；给出 10 个契约方法与 4 个平台侧扩展方法、校验规则与避坑清单。
