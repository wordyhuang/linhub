# LinHub 服务接入指南 · approval 审批服务 V1.17

> **文档创建时间**：2026-10-28 22:30:00
> **最后修改时间**：2026-11-10 12:30:00
> **当前版本**：V_1.17
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 approval 审批服务的契约方法、DSL 写法、状态机与包间隔离规则与 manifest 数据随包导出配置。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 approval 一个服务；功能包用 DSL 定义审批流程模板，提交单据后由平台引擎推进（串行或签/会签、排他网关、并行网关 + 汇合），到终态发平台事件并给提交人发站内信；包卸载时在途单据冻结为孤儿态，重装同名包自动接管；超管可在审批中心「服务设置」页开关审批工作台，配置经平台键值对服务（dictionary）持久化。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 流程 DSL 与校验规则
5. 核心语义（双快照 / 状态机 / 网关与汇合 / 整单驳回 / 事件与站内信）
6. 包间隔离与生命周期
7. 数据随包导出与导入（manifest 配置）
8. 使用要点与避坑
9. 服务设置（平台键值对持久化）
10. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `approval` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 支持（seed / all，见「数据随包导出与导入」节） |
| 契约接口 | `linhub\host\services\approval\Approval` |
| 宿主实现 | `linhub\host\services\approval\ApprovalService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |
| 服务设置存储 | 复用 dictionary 服务：namespace='host'、dict_key='approval.settings' |

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

### 3.1 声明式流程文件（resource/approvals/）

除代码内 `defineFlow` 外，功能包可提供**单 JSON 文件单流程**的声明文件，放在包根 `resource/approvals/` 目录下（无需在 manifest 声明）。安装/升级时由宿主自动注册（`ApprovalFlowSeeder` → `defineFlow`，`source='package'`，即后台流程清单「系统定义」tab）：

```json
{
  "flow_key": "leave_apply",
  "name": "请假审批",
  "entry": "n1",
  "nodes": [
    { "id": "n1", "type": "approval", "name": "主管审批", "approvers": { "users": ["u1001"] }, "mode": "any", "next": null }
  ]
}
```

语义与约束：

- **统一 upsert**：安装/升级同规则——同 `flow_key` 覆盖定义并 `version+1`；第五节 DSL 校验全部适用（含 `layout` 画布坐标可选字段）；
- **不删除**：包内已移除的旧流程定义、包卸载均不清理流程模板——在途/历史单据的节点解析依赖流程定义，删除会破坏解析；
- **P6 容错**：目录缺失、文件不可读、坏 JSON、数组形式（一文件多流程）、流程校验失败（如缺 `entry`、审批人不存在）一律收集告警跳过，不中断安装/升级；
- `approvers.users` 必须是**真实存在的用户 id**（服务层校验），写死的示例 id 会导致该文件注册失败并产生告警；
- JSON 字段与 DSL 一一对应（`flow_key`/`name`/`entry`/`nodes`/`branches`/`condition`/`layout` 均可写）。

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `defineFlow` | `defineFlow(?string $package, array $flow, string $source = 'package'): array` | 定义/覆盖流程模板，`version` 自增；校验失败抛 `ApprovalFlowInvalid`；`source` 标记来源：包侧默认 `package`（系统定义），后台流程清单保存传 `admin`（自定义） |
| `submit` | `submit(?string $package, string $flowKey, array $payload, array $options = []): string` | 提交单据，返回 `approval_no`（`APR` + 32 位 hex）；options：`title` 必填，`summary`/`submitterId`/`submitterName` 可选；**payload 是单据内容快照，必须携带单据的内容信息且字段名与取值用中文（见 §6.1）**，超 1MB 抛 `ApprovalState` |
| `get` | `get(?string $package, string $approvalNo): ?array` | 单据 + 节点实例链 + 动作时间线；不存在返回 `null` |
| `list` | `list(?string $package, array $filter, int $page = 1, int $size = 20): array` | 分页检索；filter 下划线键：`status`/`flow_key`/`submitter_id`/`keyword`/`date_from`/`date_to`/`approval_nos`（`package` 仅 host 跨包全量时生效） |
| `cancel` | `cancel(?string $package, string $approvalNo, string $comment = ''): bool` | 撤回（仅 pending）；终态抛 `ApprovalState` |
| `approve` | `approve(Identity $approver, string $approvalNo, string $comment = ''): bool` | 同意；越权抛 `ApprovalDenied`；重复提交幂等返回 `true` |
| `reject` | `reject(Identity $approver, string $approvalNo, string $comment): bool` | 驳回 → 整单 `rejected`；意见为空抛 `ApprovalState` |
| `myPending` | `myPending(Identity $viewer, int $page = 1, int $size = 20): array` | 我的待办（`status=active` 且 viewer 在快照内） |
| `registerResolver` | `registerResolver(string $name, string $handlerClass): void` | 注册自定义审批人解析器（同名覆盖） |
| `stats` | `stats(?Identity $viewer = null): array` | 用量统计：总数 / 在办 / 按状态 / 按包聚合；传 `viewer` 时额外返回 `todo`（该身份名下活跃待办单据数，与 `myPendingNos` 同源），不传为 0 |

平台审批中心额外使用的实现侧扩展方法（功能包无需调用）：

| 方法 | 说明 |
| --- | --- |
| `flows(?string $package = null)` | 流程模板列表；host 调用者不传包名时跨包全量，其余只返回本包；返回 decorate 后的行（含 `definition` 与 `source`：`package`=系统定义 / `admin`=后台自定义） |
| `cancelAs(?Identity $actor, ?string $package, string $no, string $comment)` | 代操作人撤回，写操作人信息 |
| `myHandled(Identity $viewer, int $page, int $size, string $action = '')` | 我的已办（我做过 `approve`/`reject` 动作的单据，按单据去重取最近一次动作，附 `my_action`/`my_comment`/`my_acted_at`）；`action` 传 `approve`/`reject` 时仅返回对应动作的单据（工作台「已同意 / 已驳回」页签），非法值回退全量 |
| `myWorkbenchSummary(Identity $viewer): array` | 我的工作台计数摘要：`{todo, handled, approved, rejected}`——待办单数 + 已办按最近动作分类计数（恒满足 `handled === approved + rejected`）；工作台页签徽标与角色首页统计卡共用同一数据源 |
| `myPendingNos(Identity $viewer)` | 当前用户名下有活跃审批任务的单据编号集合（与 `myPending` 同源快照匹配，去重返回数组）；配合 `list` 的 `approval_nos` 白名单（逗号分隔，SQL `IN` 下推）实现「待办」维度的分页检索 |
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

> 来源与保护（`source`）：模板行带来源标记——包侧 `defineFlow` 默认 `source='package'`（系统定义），后台「流程清单」保存传 `'admin'`（自定义）；**后台不得覆盖系统定义模板**，违例抛 `ApprovalState`（消息含「系统定义的流程模板不可在后台修改」）；包侧更新系统定义放行；覆盖更新不改写 `source`（保持首次登记值）。

> 图形布局（可选）：节点可携带 `x`/`y` 整数坐标，`defineFlow` 归一化为 `definition` 顶层的 `layout` map（`['<nodeId>' => ['x' => int, 'y' => int]]`，仅在存在坐标时输出）。该字段只服务于后台「流程设计」图形编辑器的画布还原，引擎与校验逻辑完全忽略；不传坐标时 `definition` 不含 `layout` 键，旧调用方行为不变。

---

## 六、核心语义

### 6.1 双快照

- **payload 快照**：`submit` 时把业务内容整体快照落库，之后包侧源数据变动不影响在途单据；
- **payload 必须包含单据的内容信息（硬性要求）**：payload 是审批人判断的**唯一依据**——审批中心单据详情页只展示 payload 快照与 `options` 的 `title`/`summary`，审批人不会、也不应回源读业务表。因此 payload 至少要包含：单据号/业务编号、业务名称或类型、关键金额与数量、申请事由/说明、关键明细行（如物料、金额构成）等足以独立支撑审批判断的内容。**只放业务主键 id 或一两个引用字段是不够的**（审批人看不到单据长什么样，只能退回业务系统自行查找）。敏感或超 1MB 的大对象（附件、长文本、图片）存业务表后用引用字段带入审批，但其内容摘要仍须写进 payload；
- **快照的字段名与取值文案必须使用中文（硬性要求）**：快照唯一用途是给审批人阅读——详情页把 payload 原样 JSON 格式化渲染（没有字段名映射表），英文键会让审批人看不出含义。因此 payload 的键与取值一律用中文（如 `单据号`、`业务类型`、`金额`、`申请事由`、`明细`），不要用 `order_no`/`amount`/`items` 这类英文键；日期、金额、数量等数值可保持原值。快照不参与程序判断，程序消费所需的标识请走 `approval_no`/`options` 或业务表，不要依赖 payload 键名：

  ```php
  // 推荐：字段名为中文 + 单据内容完整，审批人可直接阅读
  $ap->submit(null, 'purchase', [
      '单据号'   => 'PO-2026-0001',
      '业务类型' => '服务器采购',
      '金额'     => '6800.00 元',
      '数量'     => 2,
      '申请事由' => '服务器扩容',
      '明细'     => [
          ['名称' => '云服务器', '规格' => '8C16G', '数量' => 2, '单价' => '3400.00 元'],
      ],
      '附件引用' => [/* 业务表附件 id 列表，大对象用引用 */],
  ], ['title' => '采购申请-PO-2026-0001', 'summary' => '服务器扩容 6800 元']);

  // 不推荐：英文键 + 只放业务主键，审批人既看不懂又看不到单据内容
  $ap->submit(null, 'purchase', ['order_id' => 12345], ['title' => '采购申请']);
  ```

- **系统不做翻译，提交内容责任在开发者（硬性要求）**：审批服务对 payload/`title`/`summary` 一律**原样输出**，不做任何翻译——中文语境下，包侧必须在**提交前**自行完成内容中文化（含业务状态值/枚举值；用编码内字典还是 translation 服务由包自选），禁止把英文原文/英文键直接提交进快照；程序消费所需的英文原值可另放独立字段，展示字段一律中文。审批服务自身状态（单据/节点/动作）的中文化同样不经托管服务，由审批中心编码内固定字典完成（见 §6.2）。

- **审批人快照**：节点**激活时**才解析——`users` 直接取用、`roles` 经 RBAC 解析为当前用户、`resolver` 调用注册的解析器；合并去重后落库。快照后角色变动不影响在途节点。

### 6.2 状态机

- 单据：`pending` → `approved` / `rejected` / `cancelled`（终态）；包卸载 → 仍是 `pending` 但 `orphaned=1` 冻结；
- 节点：`waiting` → `active` → `approved` / `rejected` / `skipped` / `error`；
- 单据完成判定集中在收尾逻辑：无 `active/waiting/error` 节点且至少有一个审批节点通过 → `approved`（避免并行分支提前误判完成）；
- **状态中文化用编码内字典，不走 translation 托管服务**：审批自身状态（单据状态/节点状态/动作类型）的中文展示由审批中心代码内固定字典映射完成（前端 `STATUS_TEXT`/`NODE_TEXT`/`ACTION_TEXT`，miss 回退原文）——状态集合固定、由引擎独有，不占翻译词条、不随语言配置漂移；translation 服务只面向**包侧业务状态值**（见 §6.1）。

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

## 八、数据随包导出与导入（manifest 配置）

本服务支持「服务数据随包导出与导入」机制：打包期按 manifest 的 `data` 段把本包名下流程模板导出为包内 `data/approval.json`，安装/升级时回灌平台库。

manifest 配置（manifest.php）：

```php
'data' => [
    'enable'  => true,                      // 缺省 true；置 false 整包不随带任何服务数据
    'services' => [
        'approval' => ['scope' => 'seed'],      // seed/all 语义一致
    ],
],
```

**scope 语义**：
- `seed` / `all` 语义一致：导出本包的流程模板（DSL 定义）；**审批实例不导出**——包卸载后实例走孤儿冻结，重装自动接管。

**导入与卸载语义**：
- 安装/升级导入默认 `merge`（按 package+flow_key 覆盖更新，更新时 `version` 自增、保留既有来源 source）；`replace` 先清本包旧模板再整批写入。
- 导入行归属强制为本包短名，数据行内伪造归属字段无效；插入时 source 白名单化（package/admin）。
- 卸载时仅当本服务在 `manifest.data.services` 中显式声明才清理包名下流程模板，未声明则保留（防误删）；审批实例永远不清理。
- 单服务导出上限 50000 行，超出截断（`data/index.json` 标记 truncated）。

**脱敏边界**：无特殊脱敏。

---

## 九、使用要点与避坑

1. **必读双快照语义**：审批人快照在节点激活时冻结，不要期望运行中改角色能改变在途节点。
2. **并行汇合写虚拟标识**：分支终点必须写 `__join_<网关id>__`，不要直接写 `join` 节点 id，否则汇合计数不生效。
3. **排他网关必须有默认分支**：缺失或出现多个 `condition=null` 都会被 `defineFlow` 拒绝。
4. **payload ≤ 1MB 且必须带单据内容**：超限抛 `ApprovalState`，大对象请存业务表后用引用字段进入审批；但引用字段之外，单据号、业务类型、金额数量、申请事由、明细行等内容信息仍须写进 payload（见 §6.1），不能只传业务主键 id；**字段名与取值一律用中文**（快照仅供审批人阅读，英文键审批人看不懂）；提交内容的中文化由包侧在提交前自行完成——审批服务原样输出、不做翻译（审批自身状态由编码内字典翻译，见 §6.2）。
5. **解析器必须无参构造**：实现 `resolve(array $context): array` 返回用户 id 列表；执行失败节点标 `error`、单据停在 `pending`，并抛 `ApprovalResolverError`（消息含 `approval_no`，数据已落库不会丢）。
6. **双入口身份一致**：平台审批中心由 admin 控制器构造 `Identity`；包自建 UI 经 `loginstate` 服务取当前用户构造 `Identity`，服务只认快照匹配，不认调用来源。审批中心端点门禁分两级：工作台/单据详情页与待办/已办/计数摘要/详情数据/同意/驳回/撤回端点为**登录门禁**（全体登录用户可入，列表口径与越权由服务层兜底）；流程管理（`flowIndex/flowEditor/flowList/flowMeta/flowSave`）与全部单据（`index/rows`）仍为**超管门禁**。
7. **撤回不是删除**：`cancel` 只终止流程（终态 `cancelled`），业务数据回收请配合 recyclebin 服务。
8. **不要绕过服务写库**：审批数据归平台审批引擎所有，包侧直接写库会破坏状态机与事件一致性。
9. **工作台开关读服务层封装**：业务代码判断工作台可用性一律走 `ApprovalSettings::workbenchEnabled()`（字典服务异常时兜底视为开启，不阻断业务），不要直读 `approval.settings` 字典键；判断角色首页统计卡开关走 `ApprovalSettings::workbenchStatsEnabled()`（故障兜底为**关闭**，与另两个开关兜底方向相反）。

---

## 十、服务设置（平台键值对持久化）

超管侧「审批服务设置」页（菜单：审批服务分组 → 服务设置，`menu.superadmin.approval.settings`，路由 `/admin/ApprovalAdmin/settings`，权限 `host:approval:manage`），配置经平台键值对服务（dictionary）持久化，单 key 整体保存：

| 项 | 值 |
| --- | --- |
| namespace / dict_key | `host` / `approval.settings` |
| value | JSON：`{"workbench_enabled": true, "workbench_api_enabled": true, "workbench_stats_enabled": false}` |
| meta | `is_public=false`（平台托管不公开）、remark「审批服务设置（平台托管）」 |
| 封装类 | `linhub\host\services\approval\ApprovalSettings`（无参构造可直用） |

当前配置项：

| 键 | 类型 / 默认 | 说明 |
| --- | --- | --- |
| `workbench_enabled` | bool / `true` | 是否启用审批工作台（页面入口） |
| `workbench_api_enabled` | bool / `true` | 是否启用我的待办/已办/计数摘要数据接口（与页面开关相互独立） |
| `workbench_stats_enabled` | bool / `false` | 是否在角色首页显示个人审批统计卡（需工作台页面开关同时开启才渲染） |

端点（均超管门禁）：

| 端点 | 方法 | 说明 |
| --- | --- | --- |
| `/admin/ApprovalAdmin/settings` | GET | 设置页（Element Plus 开关表单） |
| `/admin/ApprovalAdmin/settingsData` | GET | 读取配置（`ApprovalSettings::all()`） |
| `/admin/ApprovalAdmin/settingsSave` | POST | 保存配置（白名单合并落库，记审计 `approval.settingsSave`） |

开关生效面（仅审批中心入口面，不影响单据提交/推进/事件/站内信）：

- `workbench_enabled` → `workbench` 页：关闭时渲染 `workbench_disabled` 停用提示页（HTTP 200）；同时是角色首页统计卡的渲染前提之一；
- `workbench_api_enabled` → `pendingRows` / `handledRows` / `myCounts`：关闭时返回 `code=403`（pendingRows/handledRows 文案「我的待办 / 我的已办数据接口已停用」，myCounts 文案「我的待办 / 我的已办数据接口已停用」；三开关相互独立，可任意组合）；
- `workbench_stats_enabled` → 角色首页统计卡：**三开关同开**时 `Home::renderRoleHome` 才调 `myWorkbenchSummary()` 并向四个角色首页（worker/functional_admin/system_admin/super_admin）注入统计数据（fail-soft：服务异常时注入 `null` 不渲染，不阻断首页）；点击统计卡各项经 `home-link.js` 的 `linhubOpenApprovalWorkbench(tab)` 打开审批工作台并定位对应页签（`workbench?tab=`，tab ∈ todo/done/approved/rejected）；
- 生效不分角色：超管同样受控（可在设置页自行改回）；单据详情与动作链（`detail`/`detailData`/`approve`/`reject`/`cancel`）及审批中心管理端点（`index`/`rows`/`flow*`）不受开关影响。

扩展约定：新增配置项在 `ApprovalSettings::DEFAULTS` 增加键即可——`all()` 按 `DEFAULTS` 白名单过滤 + 默认值兜底；读侧 `workbenchEnabled()` / `workbenchApiEnabled()` 内置故障兜底（异常视为开启），`workbenchStatsEnabled()` 故障兜底为关闭（首页统计卡属增强功能，异常时不渲染），`save()` 异常向上抛（保存失败必须报错）。

---

## 十一、版本变化记录

- **V1.17（2026-11-10）**：明确审批服务对单据内容（payload/`title`/`summary`）**原样输出、不做翻译**——提交内容的中文责任在包侧开发者，须提交前自行中文化（编码内字典或 translation 服务自选）；§6.1 改写原「须经 translation 服务转中文」表述，§9.4 同步。
- **V1.16（2026-11-10）**：明确审批**自身状态**（单据/节点/动作）的中文化**不走 translation 托管服务**，由审批中心编码内固定字典映射（§6.2 新增规则；§6.1、§9.4 同步收窄范围为包侧业务状态值）。
- **V1.15（2026-11-10）**：新增「快照中的英文状态值须经翻译服务转中文」硬性要求（§6.1）——中文语境下英文状态类内容须在入快照前经 translation 服务（`translate`/`translateBatch`）转中文，禁止英文原文直出给审批人；§9.4 避坑同步。
- **V1.14（2026-11-10）**：明确快照内容「字段名与取值文案必须使用中文」——快照仅供审批人阅读，详情页原样渲染 JSON、无字段名映射，英文键审批人看不懂；§6.1 增加硬性规则与中文键示例（推荐/不推荐对照），§4 `submit` 说明与 §9.4 避坑同步。
- **V1.13（2026-11-10）**：明确「提交的内容快照（payload）必须包含单据的内容信息」硬性要求——§6.1 双快照补充规则与推荐/不推荐示例（单据号、业务类型、关键金额数量、申请事由、关键明细行；大对象用引用字段但摘要仍须进 payload；禁止只传业务主键 id）；§4 `submit` 签名说明与 §9.4 避坑同步。

