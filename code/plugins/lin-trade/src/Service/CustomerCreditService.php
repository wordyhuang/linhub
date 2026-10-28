<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\CreditLog;
use Lg\Pack\Trade\model\CustomerCredit;
use Lg\Pack\Trade\model\SaleOrder;
use Lg\Pack\Trade\model\SaleOrderItem;
use Lg\Pack\Trade\model\SaleReturn;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 客户信用服务（PRD 3.9）。
 *
 * 额度管理（trade:credit:manage）：
 *  - setLimit()：调整信用额度，预留改前/改后留痕（lg_lin-trade_credit_log, action=set_limit）；
 *  - 新客户默认额度取 trade.credit.default_limit（默认 0 = 未设额度不可赊销）。
 *
 * 赊销控制（trade.credit.control：block/alert/off，默认 block）：
 *  - 占用口径 = 已出库未结清销售金额 − 已完成销售退货金额（PRD 3.9.2）；
 *  - 占用以"已出库"为准，reserve_stock 预占不参与占用；
 *  - 出库后占用 > 额度 -> block 抛异常回滚 / alert 发布 lg.trade.credit.exceeded / off 不校验。
 *
 * 结清来源：lg.finance.receivable.status 应收事件（settleFromReceivable）
 * 或手工标记已收款（manualSettle，trade.credit.manual_settle=on），均留痕。
 */
class CustomerCreditService
{
    /** 动作 */
    public const ACTION_SET_LIMIT   = 'set_limit';     // 额度调整
    public const ACTION_MANUAL_SETTLE = 'manual_settle'; // 手工标记已收款
    public const ACTION_SETTLE      = 'settle';        // 外部应收事件结清

    /**
     * 调整客户信用额度（PRD 3.9.1，trade:credit:manage）。
     *
     * @param int       $customerId
     * @param float     $newLimit  新额度（>=0）
     * @param int|null  $operator  操作人 user id
     * @param int|null  $onCreate  是否为新建客户初始化（默认额度取自配置）
     */
    public function setLimit(int $customerId, float $newLimit, ?int $operator = null, bool $onCreate = false): CustomerCredit
    {
        if ($newLimit < 0) {
            throw new \RuntimeException('信用额度不能为负数');
        }
        $credit = CustomerCredit::where('customer_id', $customerId)->find();
        $old    = $credit ? (float) $credit->credit_limit : 0.0;

        if ($onCreate && null === $newLimit) {
            $newLimit = (float) $this->config('credit.default_limit', 0);
        }

        if ($credit) {
            $credit->credit_limit = round($newLimit, 4);
            $credit->save();
        } else {
            $credit = CustomerCredit::create([
                'customer_id'  => $customerId,
                'credit_limit' => round($newLimit, 4),
                'used_amount'  => 0,
            ]);
        }

        if (!$onCreate || (float) $newLimit !== $old) {
            $this->log($customerId, self::ACTION_SET_LIMIT, $old, round($newLimit, 4), $operator, null, null);
        }
        return $credit;
    }

    /**
     * 计算客户当前信用占用（PRD 3.9.2）。
     * 占用 = 已出库未结清销售金额 − 已完成销售退货金额。
     * 已出库按销售明细 outbound_qty×price 统计；已结清(credit_settled=1)销售单不计入。
     */
    public function occupancy(int $customerId, int $excludeOrderId = 0): float
    {
        // 已出库未结清销售金额（按行：已出数量×行单价）
        // excludeOrderId：调用方处于出库事务内且明细 outbound_qty 已先行更新时，排除本单避免重复计入
        $sold = (float) Db::name('lg_lin-trade_sale_order_item')
            ->alias('si')
            ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
            ->where('so.customer_id', $customerId)
            ->where('so.credit_settled', 0)
            ->where('si.outbound_qty', '>', 0)
            ->when($excludeOrderId > 0, function ($query) use ($excludeOrderId) {
                $query->where('so.id', '<>', $excludeOrderId);
            })
            ->sum(Db::raw('si.outbound_qty * si.price'));

        // 已完成销售退货金额（回补库存/冲应收后抵减占用）
        $returned = (float) SaleReturn::where('customer_id', $customerId)
            ->where('status', 'done')
            ->sum('total_amount');

        return round($sold - $returned, 4);
    }

    /**
     * 出库前信用校验（PRD 3.9.3）。
     *
     * @param int   $customerId
     * @param float $deltaAmount 本次出库新增占用金额（本次出库行金额合计）
     * @return string 'ok'|'alert'|'block' 返回结果；block 时抛异常（调用方须已包事务以回滚）
     */
    public function checkOutbound(int $customerId, float $deltaAmount, int $excludeOrderId = 0): string
    {
        $control = (string) $this->config('credit.control', 'block');
        if ('off' === $control) {
            return 'ok';
        }
        $credit = CustomerCredit::where('customer_id', $customerId)->find();
        $limit  = $credit ? (float) $credit->credit_limit : 0.0;
        $occ    = $this->occupancy($customerId, $excludeOrderId) + $deltaAmount;

        if ($occ <= $limit) {
            return 'ok';
        }
        if ('alert' === $control) {
            TradeEvents::publish(TradeEvents::CREDIT_EXCEEDED, [
                'customer_id'   => $customerId,
                'credit_limit'  => $limit,
                'used_amount'   => $occ,
            ]);
            return 'alert';
        }
        // block
        throw new \RuntimeException(
            sprintf('客户信用额度不足：本次出库后占用[%.2f]超额度[%.2f]，已拦截出库', $occ, $limit)
        );
    }

    /**
     * 手工标记已收款（PRD 3.9.2，trade.credit.manual_settle=on）。
     * 结清某销售单信用占用并留痕。
     */
    public function manualSettle(int $saleOrderId, ?int $operator = null): bool
    {
        if ('on' !== (string) $this->config('credit.manual_settle', 'on')) {
            throw new \RuntimeException('未启用手工标记收款（trade.credit.manual_settle=off）');
        }
        $order = SaleOrder::find($saleOrderId);
        if (!$order) {
            throw new \RuntimeException('销售订单不存在');
        }
        if (!in_array($order->status, ['done', 'partial', 'closed'], true)) {
            throw new \RuntimeException('仅已完成/部分/已关闭销售单可标记结清');
        }
        if ((int) $order->credit_settled) {
            return true; // 已结清，幂等
        }

        Db::startTrans();
        try {
            $occ = $this->orderOutboundAmount($saleOrderId);
            SaleOrder::where('id', $saleOrderId)->update([
                'credit_settled' => 1,
                'settled_at'     => date('Y-m-d H:i:s'),
            ]);
            // 同步预留存量（反查 customer_id）
            $customerId = (int) $order->customer_id;
            if ($customerId) {
                $this->log($customerId, self::ACTION_MANUAL_SETTLE, $occ, 0, $operator, 'sale_order', $saleOrderId);
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 外部应收事件结清（lg.finance.receivable.status，PRD 3.9.2 结清来源）。
     *
     * @param int   $customerId
     * @param int   $saleOrderId
     * @param float $amount 结清金额（应等于该单已出库金额）
     */
    public function settleFromReceivable(int $customerId, int $saleOrderId, float $amount, ?int $operator = null): bool
    {
        $order = SaleOrder::find($saleOrderId);
        if (!$order || (int) $order->credit_settled) {
            return true;
        }
        $occ = $this->orderOutboundAmount($saleOrderId);
        Db::startTrans();
        try {
            SaleOrder::where('id', $saleOrderId)->update([
                'credit_settled' => 1,
                'settled_at'     => date('Y-m-d H:i:s'),
            ]);
            $this->log($customerId, self::ACTION_SETTLE, $occ, 0, $operator, 'sale_order', $saleOrderId);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 客户信用查看（PRD 3.9.1） */
    public function credit(int $customerId): ?array
    {
        $credit = CustomerCredit::where('customer_id', $customerId)->find();
        if (!$credit) {
            return null;
        }
        $data = $credit->toArray();
        $data['occupancy']  = $this->occupancy($customerId);
        $data['available']  = round((float) $credit->credit_limit - $data['occupancy'], 4);
        $data['credit_log'] = CreditLog::where('customer_id', $customerId)->order('id', 'desc')->select()->toArray();
        return $data;
    }

    // ---------------- 内部 ----------------

    /** 单销售单已出库金额（outbound_qty×price 汇总） */
    private function orderOutboundAmount(int $saleOrderId): float
    {
        return round((float) SaleOrderItem::where('order_id', $saleOrderId)
            ->where('outbound_qty', '>', 0)
            ->sum(Db::raw('outbound_qty * price')), 4);
    }

    /** 信用操作留痕（PRD 3.9 调整预留改前/改后） */
    private function log(int $customerId, string $action, float $before, float $after,
                         ?int $operator, ?string $refType = null, ?int $refId = null): void
    {
        CreditLog::create([
            'customer_id'   => $customerId,
            'action'        => $action,
            'before_amount' => $before,
            'after_amount'  => $after,
            'operator'      => $operator,
            'ref_type'      => $refType,
            'ref_id'        => $refId,
        ]);
    }

    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}