<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\facade\Db;

/**
 * 工作台插槽控制器：向宿主首页输出业务概览统计卡（workspace.php 声明，zone=home.top）。
 * 页面为 iframe 装载，由本控制器服务端直出统计数据（不做异步取数，
 * 保证宿主 onSlotFrameLoad 量取 iframe 内容高度时数据已渲染完成）。
 */
final class WorkspaceController extends LinCrmBaseController
{
    /** 业务概览统计插槽页（/lin-crm/workspace/stats） */
    public function pageStats()
    {
        $this->checkPerm('lin-crm:customer:view');

        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        $today      = date('Y-m-d');

        // 单行聚合查询助手：表名带连字符，必须反引号包裹
        $one = static function (string $sql, array $bind = []): array {
            $rows = Db::query($sql, $bind);
            return (array) ($rows[0] ?? []);
        };

        // ── 客户 ──
        $customerTotal = $one('SELECT COUNT(*) AS c FROM `lg_lin-crm_customer` WHERE deleted_at IS NULL')['c'] ?? 0;
        $customerNew   = $one('SELECT COUNT(*) AS c FROM `lg_lin-crm_customer` WHERE deleted_at IS NULL AND created_at >= ?', [$monthStart])['c'] ?? 0;
        $customerPool  = $one("SELECT COUNT(*) AS c FROM `lg_lin-crm_customer` WHERE deleted_at IS NULL AND pool_status = 'public_pool'")['c'] ?? 0;

        // ── 线索 ──
        $leadPool = $one("SELECT COUNT(*) AS c FROM `lg_lin-crm_lead` WHERE deleted_at IS NULL AND owner_id = 0 AND status = 'unassigned'")['c'] ?? 0;

        // ── 商机 ──
        $opp        = $one("SELECT COUNT(*) AS c, IFNULL(SUM(amount), 0) AS amt FROM `lg_lin-crm_opportunity` WHERE deleted_at IS NULL AND status = 'open'");
        $oppCnt     = (int) ($opp['c'] ?? 0);
        $oppAmount  = (float) ($opp['amt'] ?? 0);

        // ── 合同（本月新签：生效/已结算） ──
        $con           = $one("SELECT COUNT(*) AS c, IFNULL(SUM(amount), 0) AS amt FROM `lg_lin-crm_contract` WHERE deleted_at IS NULL AND status IN ('effective','settled') AND sign_date BETWEEN ? AND ?", [$monthStart, $monthEnd]);
        $contractCnt   = (int) ($con['c'] ?? 0);
        $contractAmount = (float) ($con['amt'] ?? 0);

        // ── 回款（本月计划 vs 逾期） ──
        $recvAmt = (float) ($one("SELECT IFNULL(SUM(amount - received_amount), 0) AS amt FROM `lg_lin-crm_receivable_plan` WHERE status <> 'settled' AND plan_date BETWEEN ? AND ?", [$monthStart, $monthEnd])['amt'] ?? 0);
        $recvOverdue = (int) ($one("SELECT COUNT(*) AS c FROM `lg_lin-crm_receivable_plan` WHERE status <> 'settled' AND plan_date < ?", [$today])['c'] ?? 0);

        // ── 待跟进提醒（取每客户最近一次约定的下次跟进时间，到期未跟进即计入） ──
        $dueFollow = (int) ($one("SELECT COUNT(*) AS c FROM (SELECT biz_id, MAX(next_at) AS nx FROM `lg_lin-crm_follow_up`"
            . " WHERE biz_type = 'customer' AND next_at IS NOT NULL GROUP BY biz_id) t WHERE t.nx <= NOW()")['c'] ?? 0);

        // ── 跟单（林销在途订单：草稿/已提交/部分出库；RL-5 弱依赖降级，林销未装恒 0） ──
        $orderActive = 0;
        try {
            $orderActive = (int) ($one("SELECT COUNT(*) AS c FROM `lg_lin-trade_sale_order` WHERE status IN ('draft','submitted','partial')")['c'] ?? 0);
        } catch (\Throwable $e) {
            // 林销未安装：在途订单显示 0（弱依赖降级）
        }

        return $this->render('workspace/stats', [
            'v_customer_total'  => number_format((float) $customerTotal),
            'v_customer_new'    => number_format((float) $customerNew),
            'v_customer_pool'   => number_format((float) $customerPool),
            'v_lead_pool'       => number_format((float) $leadPool),
            'v_opp_cnt'         => (string) $oppCnt,
            'v_opp_amount'      => number_format($oppAmount, 2),
            'v_contract_cnt'    => (string) $contractCnt,
            'v_contract_amount' => number_format($contractAmount, 2),
            'v_recv_amount'     => number_format($recvAmt, 2),
            'v_recv_overdue'    => (string) $recvOverdue,
            'v_recv_warn'       => $recvOverdue > 0 ? ' warn' : '',
            'v_due_follow'      => (string) $dueFollow,
            'v_due_warn'        => $dueFollow > 0 ? ' warn' : '',
            'v_order_active'    => (string) $orderActive,
        ]);
    }
}
