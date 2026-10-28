<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

use linhub\capability\auth\Identity;

/**
 * 审批服务契约（平台第 17 个公共服务，AP）。
 *
 * 定位：功能包将业务审批托管给平台——包用 DSL 定义流程模板，提交审批单后由引擎
 * 按节点链推进（串行 any/all、排他网关、并行网关 + 汇合）；节点激活时解析审批人
 * 快照并发站内信提醒，单据到终态发布平台事件并通知提交人；包卸载时在途单据冻结
 * 为孤儿态，重装同名包自动接管恢复推进。
 *
 * 流程定义 DSL（flow 结构，defineFlow 入参）：
 *  - flow_key / name / entry(首节点 id，必须存在) / nodes[]
 *  - 节点 type：approval（审批节点）/ exclusive（排他网关）/ parallel（并行网关）
 *  - approval 节点：approvers{users[],roles[],resolver} 至少一来源（混合时解析合并去重）、
 *    mode any(或签)/all(会签)、next(下一节点 id，null = 流程终点)
 *  - exclusive 节点：branches[]，每支 {condition, next}；有且仅有一个 condition=null 默认分支；
 *    运行时按声明顺序取首个命中分支，无命中走默认
 *  - parallel 节点：branches[]（无 condition，{next}），branches ≥ 2；join = 汇合节点 id
 *    （必须存在且不是网关自身）；各分支 next 最终指向 "__join_<网关id>__" 虚拟汇合标识
 *  - 条件 condition：{field,op,value}，field ∈ payload.*（提交内容）/ submitter.*（发起人上下文），
 *    op ∈ eq/ne/gt/gte/lt/lte/in/not_in/contains/exists；可嵌套 {all:[...]}/{any:[...]}，深度 ≤ 3
 *
 * 快照语义（双快照）：
 *  - payload 快照：submit 时内容快照落库，后续不受包侧源数据变动影响；
 *  - 审批人快照：节点激活时将 users 直接取用、roles 经 RBAC 解析、resolver 调用
 *    注册的解析器类，合并去重落库；快照后角色变动不影响在途节点。
 *
 * 状态机：
 *  - 单据 pending → approved/rejected/cancelled（终态）；包卸载 → pending + orphaned=1 冻结；
 *  - 节点 waiting → active → approved/rejected/skipped/error；
 *  - 任一人驳回 → 整单立即 rejected（D7 整单驳回），全部 active/waiting 节点终止；
 *  - 并行网关：各分支首节点同时 active，全部分支到达汇合点后 join 后继节点激活。
 *
 * 事件（messaging 总线，宿主保留前缀）：
 *  - lg.platform.approval.submitted：submit 成功（approval_no/package/flow_key/title/submitter_id/首节点快照）
 *  - lg.platform.approval.completed：单据到终态（approval_no/package/status/finished_at）
 *
 * 站内信（服务内部自动）：节点激活 → 提醒审批人快照内全部用户；单据终态 → 通知提交人。
 *
 * 身份传递（双入口）：
 *  - 平台审批中心：admin 控制器取当前登录用户构造 Identity 传入；
 *  - 包自建 UI：包通过 loginstate 服务 snapshot() 获取当前用户，构造 Identity 传入。
 *  服务校验审批人是否在当前节点审批人快照内，越权抛 ApprovalDenied。
 *
 * 调用方语义（与 recyclebin 一致）：$package = null 时静态回溯调用链判定 caller
 * 所属功能包；单据/流程按包隔离，跨包访问抛 ApprovalDenied（读与写同规则）。
 */
interface Approval
{
    /**
     * 注册/更新流程模板（覆盖式更新，version 自增）；校验失败抛 ApprovalFlowInvalid。
     *
     * $flow 结构见类注释 DSL 说明；flow_key 包内唯一。
     * 返回流程定义记录（含 package/flow_key/version/definition 等）。
     */
    public function defineFlow(?string $package, array $flow): array;

    /**
     * 提交审批单：payload 快照落库，首节点激活。
     *
     * options 驼峰键：
     *  - title         string 必填，单据标题
     *  - summary       string 摘要（可选）
     *  - submitterId   string 提交人 id（可选，缺省取 identity 上下文无则报错语义由实现定）
     *  - submitterName string 提交人名（可选）
     *
     * @return string approval_no（格式 APR + 32 位随机 hex）
     * @throws ApprovalFlowInvalid   flow_key 未注册或流程已停用
     * @throws ApprovalState         payload 超过 1MB 或 options 非法（title 缺失等）
     */
    public function submit(?string $package, string $flowKey, array $payload, array $options = []): string;

    /**
     * 单据详情：单据 + 节点实例链 + 动作时间线；不存在返回 null。
     * 跨包访问他包单据抛 ApprovalDenied（$package = null 按 caller 包判）。
     */
    public function get(?string $package, string $approvalNo): ?array;

    /**
     * 按包检索（filter 下划线键）：status / flow_key / submitter_id /
     * keyword（approval_no/title/summary/submitter_name 模糊） / date_from / date_to。
     * $package = null 且 caller 为 host 时跨包全量（审批中心「全部单据」用）。
     */
    public function list(?string $package, array $filter, int $page = 1, int $size = 20): array;

    /**
     * 撤回（仅 pending 且未终态可撤）：终止全部 active 节点，终态事件发布；
     * 终态单据撤回抛 ApprovalState。操作人从调用链上下文取（包代撤），
     * 平台侧撤回走审批中心控制器并另落 auditTrail。
     */
    public function cancel(?string $package, string $approvalNo, string $comment = ''): bool;

    /**
     * 审批通过（双入口之一）：校验 $approver 在当前 active 节点审批人快照内，
     * 越权抛 ApprovalDenied；终态/孤儿单据操作抛 ApprovalState。
     * 同一审批人命中多个 active 节点（并行分支）时取激活最早的节点处理。
     * 或签任一人同意即节点通过；会签累计至全员同意；节点通过后推进下一节点。
     */
    public function approve(Identity $approver, string $approvalNo, string $comment = ''): bool;

    /**
     * 审批驳回：整单立即 rejected（D7），全部 active/waiting 节点终止，
     * completed 事件发布，提交人收 inbox 通知。comment 必填（空抛 ApprovalState）。
     */
    public function reject(Identity $approver, string $approvalNo, string $comment): bool;

    /**
     * 我的待办（按审批人快照检索，审批中心/包侧通用）：
     * 当前 status=active 且 viewer 在快照内的单据分页。
     */
    public function myPending(Identity $viewer, int $page = 1, int $size = 20): array;

    /**
     * 注册自定义审批人解析器（D12）：handlerClass 需无参构造 + resolve(array $context): array
     * 返回用户 id 列表；context = {package, flow_key, payload, submitter_id, submitter_name}。
     * 同名覆盖式注册；解析器执行失败抛 ApprovalResolverError（节点标 error，单据保持 pending）。
     */
    public function registerResolver(string $name, string $handlerClass): void;

    /** 平台用量统计（PS 看板口径）：总数/在办/按状态/按包聚合。 */
    public function stats(): array;
}
