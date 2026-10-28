<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm;

/**
 * 林客事件主题常量（前缀 lg.lin-crm.，与需求清单附录 C 一致）
 */
final class LinCrmEvent
{
    // ── 发布（6） ───────────────────────────────────────────
    /** 客户释放回公海（定时任务/手动收回，FR-CUS-02/05） */
    public const CUSTOMER_RELEASE      = 'lg.lin-crm.customer.release';
    /** 线索转化成功（FR-LEA-05） */
    public const LEAD_CONVERT          = 'lg.lin-crm.lead.convert';
    /** 商机阶段变更含回退（FR-OPP-02） */
    public const OPPORTUNITY_STAGE     = 'lg.lin-crm.opportunity.stage_change';
    /** 商机赢单/输单（FR-OPP-02） */
    public const OPPORTUNITY_RESULT    = 'lg.lin-crm.opportunity.result';
    /** 合同审批通过/驳回（FR-CON-02） */
    public const CONTRACT_AUDIT_RESULT = 'lg.lin-crm.contract.audit_result';
    /** 回款登记核销（FR-CON-04） */
    public const RECEIVABLE_PAID       = 'lg.lin-crm.receivable.paid';

    // ── 订阅（1） ───────────────────────────────────────────
    /** 林销订单状态回流（EVT-SUB-01，⛔OI-001 契约签署前 handler 不启用处理逻辑） */
    public const TRADE_ORDER_STATUS    = 'lg.trade.order.status';
}
