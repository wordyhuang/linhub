<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\PurchaseOrder;
use Lg\Pack\Trade\model\PurchaseOrderItem;
use Lg\Pack\Trade\model\PurchaseReturn;
use Lg\Pack\Trade\model\Reconciliation;
use Lg\Pack\Trade\model\SaleOrder;
use Lg\Pack\Trade\model\SaleOrderItem;
use Lg\Pack\Trade\model\SaleReturn;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 对账单服务（PRD 3.5 对账单增强 / 4.3 状态机）。
 *
 * 客户对账：期初欠款 + 本期销售 − 本期销售退货 − 本期收款 = 期末欠款；
 * 供应商对账：期初应付 + 本期采购 − 本期采购退货 − 本期付款 = 期末应付。
 *
 * 生成时对期间数据做快照锁定（snapshot 字段），生成后期间内的单据变动归下期；
 * 状态机：generated → confirmed → sent；generated/confirmed 可作废。
 * 生成动作发布 lg.trade.reconciliation.generated（供林账协同对账）。
 */
class ReconciliationService
{
    public const STATUS_GENERATED = 'generated';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SENT      = 'sent';
    public const STATUS_VOIDED    = 'voided';

    public const BIZ_CUSTOMER = 'customer';
    public const BIZ_SUPPLIER = 'supplier';

    /** 统计时参与对账的单据状态（排除草稿/作废） */
    private const ORDER_ACTIVE = ['submitted', 'partial', 'done', 'closed'];

    /**
     * 生成对账单（PRD 3.5，trade:reconciliation:generate）。
     *
     * @param string $bizType    customer|supplier
     * @param int    $targetId   客户或供应商 id
     * @param string $periodStart 期间开始 Y-m-d
     * @param string $periodEnd   期间结束 Y-m-d
     * @return array 生成的对账单明细（含 balance_amount）
     */
    public function generate(string $bizType, int $targetId, string $periodStart, string $periodEnd,
                             ?int $operator = null): array
    {
        if (!in_array($bizType, [self::BIZ_CUSTOMER, self::BIZ_SUPPLIER], true)) {
            throw new \RuntimeException('不支持的账单类型：' . $bizType);
        }
        if ($periodEnd < $periodStart) {
            throw new \RuntimeException('对账期间不合法：结束日期早于开始日期');
        }

        // 期初 = 上个对账期间（不含本期间数据）的期末余额，命中已作废则忽略
        $opening = (float) Reconciliation::where('biz_type', $bizType)
            ->where('target_id', $targetId)
            ->where('status', '<>', self::STATUS_VOIDED)
            ->where('period_end', '<', $periodStart)
            ->order('period_end', 'desc')
            ->value('balance_amount');

        // 汇总本期单据（含 单据级快照）
        $summary = self::BIZ_CUSTOMER === $bizType
            ? $this->summarizeCustomer($targetId, $periodStart, $periodEnd)
            : $this->summarizeSupplier($targetId, $periodStart, $periodEnd);

        $closing = round(
            $opening + $summary['in_amount'] - $summary['return_amount'] - $summary['pay_amount'],
            2
        );

        $snapshot = json_encode([
            'biz_type'        => $bizType,
            'target_id'       => $targetId,
            'period_start'    => $periodStart,
            'period_end'      => $periodEnd,
            'opening'         => $opening,
            'in_amount'       => $summary['in_amount'],
            'return_amount'   => $summary['return_amount'],
            'pay_amount'      => $summary['pay_amount'],
            'closing'         => $closing,
            'orders'          => $summary['orders'],
            'returns'         => $summary['returns'],
        ], JSON_UNESCAPED_UNICODE);

        $rcNo = $this->docNumberService()->next('RC', 'lg_lin-trade_reconciliation', 'rc_no');

        $rec = Reconciliation::create([
            'rc_no'          => $rcNo,
            'biz_type'       => $bizType,
            'target_id'      => $targetId,
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'status'         => self::STATUS_GENERATED,
            'balance_amount' => $closing,
            'snapshot'       => $snapshot,
            'created_by'     => $operator,
        ]);

        // 先落库后广播（协议 N5）
        TradeEvents::publish(TradeEvents::RECONCILIATION_GENERATED, [
            'reconciliation_id' => (int) $rec->id,
            'rc_no'             => $rcNo,
            'biz_type'          => $bizType,
            'target_id'         => $targetId,
            'period_start'      => $periodStart,
            'period_end'        => $periodEnd,
            'balance_amount'    => $closing,
        ]);

        return $this->detail((int) $rec->id);
    }

    /** 确认对账单（generated → confirmed） */
    public function confirm(int $id, ?int $operator = null): bool
    {
        $rec = $this->find($id);
        $this->guardStatus($rec->status, [self::STATUS_GENERATED], '仅已生成状态可确认');
        return Reconciliation::where('id', $id)->update([
            'status' => self::STATUS_CONFIRMED,
        ]) >= 0;
    }

    /** 标记已发送（经导出/打印即视为已发送，PRD 3.5；confirmed/generated → sent） */
    public function markSent(int $id, ?int $operator = null): bool
    {
        $rec = $this->find($id);
        if (!in_array($rec->status, [self::STATUS_GENERATED, self::STATUS_CONFIRMED], true)) {
            throw new \RuntimeException('当前状态不可标记已发送');
        }
        return Reconciliation::where('id', $id)->update([
            'status' => self::STATUS_SENT,
        ]) >= 0;
    }

    /** 作废对账单（generated/confirmed → voided，供重生成） */
    public function void(int $id, ?int $operator = null, ?string $reason = null): bool
    {
        $rec = $this->find($id);
        if (!in_array($rec->status, [self::STATUS_GENERATED, self::STATUS_CONFIRMED], true)) {
            throw new \RuntimeException('仅已生成/已确认对账单可作废');
        }
        return Reconciliation::where('id', $id)->update([
            'status' => self::STATUS_VOIDED,
            'remark' => $reason ?: $rec->remark,
        ]) >= 0;
    }

    /** 查看对账单详情（含快照，trade:reconciliation:view） */
    public function detail(int $id): ?array
    {
        $rec = Reconciliation::find($id);
        if (!$rec) {
            return null;
        }
        $data = $rec->toArray();
        $data['snapshot'] = $data['snapshot'] ? json_decode($data['snapshot'], true) : null;
        return $data;
    }

    /** 期间内对账单列表（按目标过滤） */
    public function listByTarget(string $bizType, int $targetId, int $limit = 50): array
    {
        return Reconciliation::where('biz_type', $bizType)
            ->where('target_id', $targetId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    // ---------------- 内部 ----------------

    /** 客户对账汇总（销售出库金额 / 已完成退货额 / 本期已收款） */
    private function summarizeCustomer(int $customerId, string $start, string $end): array
    {
        $startDt = $start . ' 00:00:00';
        $endDt   = $end . ' 23:59:59';

        // 本期销售（已出库金额，按 sales 订单出库行计）
        $orders = Db::name('lg_lin-trade_sale_order')
            ->alias('so')
            ->leftJoin('lg_lin-trade_sale_order_item si', 'si.order_id = so.id')
            ->where('so.customer_id', $customerId)
            ->where('so.status', 'in', self::ORDER_ACTIVE)
            ->whereBetween('so.created_at', [$startDt, $endDt])
            ->where('si.outbound_qty', '>', 0)
            ->field('so.id as order_id, so.order_no, so.created_at, SUM(si.outbound_qty * si.price) as amount')
            ->group('so.id, so.order_no, so.created_at')
            ->select()
            ->toArray();

        $orderAmount = 0.0;
        foreach ($orders as &$o) {
            $o['amount'] = round((float) $o['amount'], 2);
            $orderAmount += $o['amount'];
        }
        unset($o);

        // 本期销售退货（已完成）
        $returns = SaleReturn::where('customer_id', $customerId)
            ->where('status', 'done')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->field('id, return_no, created_at, total_amount')
            ->select()
            ->toArray();
        $returnAmount = round((float) array_sum(array_column((array) $returns, 'total_amount')), 2);

        // 本期收款 = 结清时间在本期间内的销售单已出库金额（手工/应收结清）
        $paidRows = Db::name('lg_lin-trade_sale_order')
            ->alias('so')
            ->leftJoin('lg_lin-trade_sale_order_item si', 'si.order_id = so.id')
            ->where('so.customer_id', $customerId)
            ->where('so.credit_settled', 1)
            ->whereBetween('so.settled_at', [$startDt, $endDt])
            ->where('si.outbound_qty', '>', 0)
            ->field('so.id as order_id, so.order_no, so.settled_at, SUM(si.outbound_qty * si.price) as amount')
            ->group('so.id, so.order_no, so.settled_at')
            ->select()
            ->toArray();
        $payAmount   = round((float) array_sum(array_column((array) $paidRows, 'amount')), 2);
        $payDetails  = [];
        foreach ($paidRows as $p) {
            $payDetails[] = [
                'order_no' => $p['order_no'],
                'paid_at'  => $p['settled_at'],
                'amount'   => round((float) $p['amount'], 2),
            ];
        }

        return [
            'in_amount'     => round($orderAmount, 2),
            'return_amount' => $returnAmount,
            'pay_amount'    => $payAmount,
            'orders'        => $orders,
            'returns'       => $returns,
            'payments'      => $payDetails,
        ];
    }

    /** 供应商对账汇总（本期入库金额 / 本期采购退货 / 本期付款） */
    private function summarizeSupplier(int $supplierId, string $start, string $end): array
    {
        $startDt = $start . ' 00:00:00';
        $endDt   = $end . ' 23:59:59';

        // 本期采购（已入库金额）
        $orders = Db::name('lg_lin-trade_purchase_order')
            ->alias('po')
            ->leftJoin('lg_lin-trade_purchase_order_item pi', 'pi.order_id = po.id')
            ->where('po.supplier_id', $supplierId)
            ->where('po.status', 'in', self::ORDER_ACTIVE)
            ->whereBetween('po.created_at', [$startDt, $endDt])
            ->where('pi.received_qty', '>', 0)
            ->field('po.id as order_id, po.order_no, po.created_at, SUM(pi.received_qty * pi.price) as amount')
            ->group('po.id, po.order_no, po.created_at')
            ->select()
            ->toArray();

        $orderAmount = 0.0;
        foreach ($orders as &$o) {
            $o['amount'] = round((float) $o['amount'], 2);
            $orderAmount += $o['amount'];
        }
        unset($o);

        // 本期采购退货（已完成）
        $returns = PurchaseReturn::where('supplier_id', $supplierId)
            ->where('status', 'done')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->field('id, return_no, created_at, total_amount')
            ->select()
            ->toArray();
        $returnAmount = round((float) array_sum(array_column((array) $returns, 'total_amount')), 2);

        // 供应商付款暂无专表/手工标记，本期付款计 0（对账单供核对，差异由线下对账确认）
        return [
            'in_amount'     => round($orderAmount, 2),
            'return_amount' => $returnAmount,
            'pay_amount'    => 0.0,
            'orders'        => $orders,
            'returns'       => $returns,
            'payments'      => [],
        ];
    }

    private function find(int $id): Reconciliation
    {
        $rec = Reconciliation::find($id);
        if (!$rec) {
            throw new \RuntimeException('对账单不存在');
        }
        return $rec;
    }

    private function guardStatus(string $actual, array $allowed, string $msg): void
    {
        if (!in_array($actual, $allowed, true)) {
            throw new \RuntimeException($msg);
        }
    }

    private function docNumberService(): DocNumberService
    {
        return App::make(DocNumberService::class);
    }
}