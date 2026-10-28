<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\CustomerCreditService;
use Lg\Pack\Trade\Service\DraftService;
use Lg\Pack\Trade\model\CustomerCredit;
use think\facade\Db;
use think\Response;

/**
 * 审批与待办中心控制器（V_1.1，PG-COM-01）。
 *
 * 聚合只读视图：待我审批（销售单审核 / 最低售价审批）、
 * 我的待办（库存预警 / 到货超期 / 草稿到期 / 信用超额）、已处理（价格审批历史）。
 * 处理动作不在本页内联，点击跳转对应业务页操作。
 */
class Todo extends TradeController
{
    public function __construct(
        private DraftService $draft,
        private CustomerCreditService $credit
    ) {
    }

    /** GET /trade/todos —— 审批与待办中心页 */
    public function index(): Response
    {
        return $this->render('todos');
    }

    /** GET /trade/todos/rows —— 三页签聚合数据 */
    public function rows(): Response
    {
        return $this->jsonOk([
            'audit' => $this->auditRows(),
            'todo'  => $this->todoRows(),
            'done'  => $this->doneRows(),
        ]);
    }

    /** GET /trade/todos/count —— 待办/待审批总数（轻量计数，供菜单角标轮询，口径与 rows 一致） */
    public function count(): Response
    {
        $audit = (int) Db::name('lg_lin-trade_sale_order')->where('status', 'pending_audit')->count()
            + (int) Db::name('lg_lin-trade_price_approval')->where('status', 'pending')->count();

        $lowTotal = (int) Db::name('lg_lin-trade_inventory')->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereColumn('i.quantity', '<', 'p.safety_stock')
            ->count();
        $overdue = (int) Db::name('lg_lin-trade_purchase_order')
            ->whereIn('status', ['submitted', 'partial'])
            ->whereNotNull('expected_arrival_at')
            ->where('expected_arrival_at', '<', date('Y-m-d'))
            ->count();

        // 草稿到期计数（与 rows 同口径：超期档剔除与提醒档重叠的单据）
        $expired = $this->draft->expiredDrafts();
        $expiredKeys = [];
        foreach ($expired as $r) {
            $expiredKeys[$r['doc_type'] . '#' . $r['id']] = true;
        }
        $remindCount = 0;
        foreach ($this->draft->remindDrafts() as $r) {
            if (!isset($expiredKeys[$r['doc_type'] . '#' . $r['id']])) {
                $remindCount++;
            }
        }

        $creditOver = 0;
        $credits = CustomerCredit::select()->toArray();
        foreach ($credits as $row) {
            $limit = (float) $row['credit_limit'];
            if ($limit > 0 && (float) $this->credit->occupancy((int) $row['customer_id']) > $limit) {
                $creditOver++;
            }
        }

        return $this->jsonOk([
            'audit' => $audit,
            'todo'  => $lowTotal + $overdue + $remindCount + count($expired) + $creditOver,
        ]);
    }

    /** 待我审批：待审核销售单 + 待审批最低售价（含申请价/底线快照） */
    private function auditRows(): array
    {
        $saleOrders = Db::name('lg_lin-trade_sale_order')->alias('so')
            ->leftJoin('lg_lin-trade_customer c', 'c.id = so.customer_id')
            ->where('so.status', 'pending_audit')
            ->field('so.id, so.order_no, so.customer_id, c.name as customer_name,
                     so.total_amount, so.created_at')
            ->order('so.id', 'desc')
            ->limit(50)
            ->select()->toArray();

        $priceApprovals = Db::name('lg_lin-trade_price_approval')->alias('pa')
            ->leftJoin('lg_lin-trade_product p', 'p.id = pa.product_id')
            ->leftJoin('lg_lin-trade_sale_order so', 'so.id = pa.sale_order_id')
            ->where('pa.status', 'pending')
            ->field('pa.id, pa.sale_order_id, so.order_no, p.name as product_name,
                     pa.applied_price, pa.min_price, pa.created_at')
            ->order('pa.id', 'desc')
            ->limit(50)
            ->select()->toArray();

        return compact('saleOrders', 'priceApprovals');
    }

    /** 我的待办：库存预警汇总 / 到货超期采购单 / 草稿到期 / 信用超额客户 */
    private function todoRows(): array
    {
        // 库存预警：总数 + 前 10 条样例（复用 PG-INV-05 同口径：库存 < 安全库存）
        $lowBase = fn () => Db::name('lg_lin-trade_inventory')->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereColumn('i.quantity', '<', 'p.safety_stock');
        $lowTotal = (int) $lowBase()->count();
        $lowStock = [];
        if ($lowTotal > 0) {
            $whMap = Db::name('lg_lin-trade_warehouse')->column('name', 'id');
            $lowStock = $lowBase()
                ->field('i.product_id, p.name as product_name, p.product_no,
                         i.warehouse_id, i.quantity, p.safety_stock')
                ->order('i.id', 'asc')
                ->limit(10)
                ->select()->toArray();
            foreach ($lowStock as &$r) {
                $r['warehouse_name'] = $whMap[(int) $r['warehouse_id']] ?? '-';
                $r['gap'] = max(0, (float) $r['safety_stock'] - (float) $r['quantity']);
            }
            unset($r);
        }

        // 到货超期：已提交/部分入库 且 预计到货日早于今天（与采购跟踪 overdue 同口径）
        $overdue = Db::name('lg_lin-trade_purchase_order')
            ->whereIn('status', ['submitted', 'partial'])
            ->whereNotNull('expected_arrival_at')
            ->where('expected_arrival_at', '<', date('Y-m-d'))
            ->field('id, order_no, expected_arrival_at, status')
            ->order('expected_arrival_at', 'asc')
            ->limit(20)
            ->select()->toArray();

        // 草稿到期：提醒期 + 超保留期（DraftService 同口径）
        // remindDrafts 的取数区间覆盖 expiredDrafts（前者 keep-3 天，后者 keep 天），
        // 故从提醒档剔除已超期单据，避免同一单据在两档重复出现。
        $expired = $this->draft->expiredDrafts();
        $expiredKeys = [];
        foreach ($expired as $r) {
            $expiredKeys[$r['doc_type'] . '#' . $r['id']] = true;
        }
        $remind = [];
        foreach ($this->draft->remindDrafts() as $r) {
            if (!isset($expiredKeys[$r['doc_type'] . '#' . $r['id']])) {
                $remind[] = $r;
            }
        }
        $drafts = ['remind' => $remind, 'expired' => $expired];

        // 信用超额：占用 > 额度的客户（CustomerCreditService::occupancy 同口径）
        $creditOver = [];
        $credits = CustomerCredit::with(['customer'])->select()->toArray();
        foreach ($credits as $row) {
            $limit    = (float) $row['credit_limit'];
            $occupied = (float) $this->credit->occupancy((int) $row['customer_id']);
            if ($limit > 0 && $occupied > $limit) {
                $creditOver[] = [
                    'customer_id'   => (int) $row['customer_id'],
                    'customer_name' => $row['customer']['name'] ?? ('客户#' . $row['customer_id']),
                    'credit_limit'  => $limit,
                    'occupancy'     => round($occupied, 2),
                    'exceed'        => round($occupied - $limit, 2),
                ];
            }
        }

        return compact('lowTotal', 'lowStock', 'overdue', 'drafts', 'creditOver');
    }

    /** 已处理：最低售价审批历史（approved/rejected，含结果/原因/时间） */
    private function doneRows(): array
    {
        $priceHistory = Db::name('lg_lin-trade_price_approval')->alias('pa')
            ->leftJoin('lg_lin-trade_product p', 'p.id = pa.product_id')
            ->leftJoin('lg_lin-trade_sale_order so', 'so.id = pa.sale_order_id')
            ->whereIn('pa.status', ['approved', 'rejected'])
            ->field('pa.id, pa.sale_order_id, so.order_no, p.name as product_name,
                     pa.applied_price, pa.min_price, pa.status, pa.reason, pa.created_at')
            ->order('pa.id', 'desc')
            ->limit(50)
            ->select()->toArray();

        return compact('priceHistory');
    }
}
