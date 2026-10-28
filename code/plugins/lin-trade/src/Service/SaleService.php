<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Customer;
use Lg\Pack\Trade\model\PriceApproval;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\SaleOrder;
use Lg\Pack\Trade\model\SaleOrderItem;
use Lg\Pack\Trade\model\Warehouse;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 销售订单服务。
 *
 * 状态机：draft(草稿) -> submitted(已提交) -> partial/done(部分/完成出库)，
 * 已提交/进行中可出库，完成后可 closed(关闭)；draft/submitted 且无出库时可 voided(作废)。
 * 出库走库存扣减，负库存红线由 InventoryService 强制；出库前按客户信用校验（PRD 3.9）。
 *
 * V_1.1 价格与信用接入：
 *  - create() 解析带价（手工>促销>最近成交>等级>参考），低于最低售价时写价格审批留痕；
 *  - submit() 校验该单所有最低售价审批均已通过；
 *  - outbound() 出库前调用信用校验。
 */
class SaleService
{
    public function __construct(
        private InventoryService $inventory,
        private DocNumberService $docNo,
        private SaleReturnService $returnSvc,
        private PriceRuleService $priceRule,
        private CustomerCreditService $credit
    ) {
    }

    /**
     * 创建销售订单（草稿），可选由外部转单（source_topic 记录来源，如 lg.crm.deal.closed）。
     */
    public function create(array $data, int $userId, ?string $sourceTopic = null): SaleOrder
    {
        if (empty($data['items'])) {
            throw new \RuntimeException('销售订单至少需要一行明细');
        }
        if (empty($data['warehouse_id'])) {
            throw new \RuntimeException('缺少出库仓库');
        }

        // 整单优惠（PRD 3.3.1 / trade.sale.allow_discount 允许时生效）：优惠金额或折扣率二选一
        $discAmount = round((float) ($data['discount_amount'] ?? 0), 2);
        $discRate   = round((float) ($data['discount_rate'] ?? 0), 4);
        if ($discAmount < 0 || $discRate < 0 || $discRate > 1) {
            throw new \RuntimeException('整单优惠金额/折扣率不合法');
        }

        Db::startTrans();
        try {
            $order = SaleOrder::create([
                'order_no'     => $this->genOrderNo(),
                'customer_id'  => $data['customer_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'status'       => 'draft',
                'total_amount' => 0,
                'discount_rate'   => $discRate,
                'discount_amount' => $discAmount,
                'outbound_no'  => 0,
                'remark'       => $data['remark'] ?? null,
                'created_by'   => $userId,
                'source_topic' => $sourceTopic,
            ]);
            $items = $this->saveItems((int) $order->id, (int) ($order->customer_id ?? 0), $data['items']);
            // 整单金额 = 明细合计 − 整单优惠（下限 0）
            $order->total_amount = max(0, round($items['amount'] - $discAmount, 2));
            $order->save();
            Db::commit();

            TradeEvents::publish(TradeEvents::SALE_ORDER_CREATED, [
                'order_id'   => $order->id,
                'order_no'   => $order->order_no,
                'customer_id'=> $order->customer_id,
                'source_topic'=> $sourceTopic,
                'lines'      => $items['rows'],
            ]);
            return $order;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function submit(int $id): bool
    {
        $order = SaleOrder::find($id);
        $this->assertExists($order);
        if ('draft' !== $order->status) {
            throw new \RuntimeException('仅草稿状态可提交，当前：' . $order->status);
        }
        // 最低售价审批校验（PRD 3.8.1）：存在未通过审批的行不可提交
        $this->priceRule->assertApprovedForSubmit($id);
        // 审核流（PRD 3.3.1，配置 trade.sale_order.require_audit，默认关闭）：开启时提交进入"待审核"
        $status = $this->config('sale_order.require_audit', false) ? 'pending_audit' : 'submitted';

        Db::startTrans();
        try {
            SaleOrder::where('id', $id)->update(['status' => $status]);
            // 预占库存（配置 trade.order.reserve_stock 开启且进入"已提交"时执行；待审核不预占，审核通过时预占）
            if ('submitted' === $status && $this->reserveEnabled()) {
                $this->reserveForOrder($order);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return true;
    }

    /**
     * 销售订单审核（PRD 3.3.1 审核流，配置 trade.sale_order.require_audit 开启时使用，权限 trade:sale:audit）。
     *
     * @param int  $id       订单 id
     * @param bool $approve  true=通过进入已提交；false=驳回回到草稿（可修改后重提）
     * @param int  $operator 审核人 user id
     * @param string $reason 审核备注
     */
    public function audit(int $id, bool $approve, int $operator = 0, string $reason = ''): bool
    {
        $order = SaleOrder::find($id);
        $this->assertExists($order);
        if ('pending_audit' !== $order->status) {
            throw new \RuntimeException('仅待审核状态可审核，当前：' . $order->status);
        }
        Db::startTrans();
        try {
            if ($approve) {
                // 审核通过前再次校验最低售价审批（PRD 3.8.1）
                $this->priceRule->assertApprovedForSubmit($id);
                SaleOrder::where('id', $id)->update(['status' => 'submitted', 'audit_reason' => $reason ?: null]);
                // 审核通过后预占库存（配置开启时）
                if ($this->reserveEnabled()) {
                    $this->reserveForOrder($order);
                }
            } else {
                // 驳回：回到草稿，可修改后重提；释放可能存在的预占
                SaleOrder::where('id', $id)->update([
                    'status'       => 'draft',
                    'audit_reason' => $reason ?: '审核驳回',
                ]);
                if ($this->reserveEnabled()) {
                    $this->releaseOrderReserve($order);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return true;
    }

    /**
     * 销售出库（核心业务，走库存扣减）。
     */
    public function outbound(int $id, array $rows, string $operator): void
    {
        $order = SaleOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['submitted', 'partial', 'done'], true)) {
            throw new \RuntimeException('当前状态不允许出库：' . $order->status);
        }
        if (empty($rows)) {
            throw new \RuntimeException('出库明细不能为空');
        }

        Db::startTrans();
        try {
            // 出库前信用校验（PRD 3.9.3）：block 模式超额度抛异常，此处提前计算本次出库新增占用
            $deltaAmount = 0.0;
            $invRows = [];
            foreach ($rows as $itemId => $deltaOut) {
                $item = SaleOrderItem::where('order_id', $id)->where('id', $itemId)->find();
                if (!$item) {
                    throw new \RuntimeException('明细不存在：' . $itemId);
                }
                $deltaOut = (float) $deltaOut;
                $remaining = (float) $item->quantity - (float) $item->outbound_qty;
                if ($deltaOut <= 0 || $deltaOut > $remaining) {
                    throw new \RuntimeException("明细{$itemId}出库数量超出剩余可出库量[{$remaining}]");
                }
                $deltaAmount += $deltaOut * (float) $item->price;
                $item->outbound_qty += $deltaOut;
                $item->save();

                $invRows[] = [
                    'change_type' => InventoryService::TYPE_SALE_OUT,
                    'product_id'  => (int) $item->product_id,
                    'warehouse_id' => (int) $order->warehouse_id,
                    'delta'       => -$deltaOut,
                    'ref_id'      => $id,
                ];
            }

            // 信用校验：非"散单"（手填客户）且配置非 off 时执行（PRD 3.3.2 / 3.9）
            // 排除本单：事务内明细 outbound_qty 已先行更新，occupancy 需排除本单再叠加本次金额
            if ($order->customer_id) {
                $this->credit->checkOutbound((int) $order->customer_id, round($deltaAmount, 4), $id);
            }

            // 扣减库存，负库存时此处抛异常整体回滚
            $this->inventory->applyMany($invRows, $operator);

            // 释放本次出库量的库存预占（配置 trade.order.reserve_stock 开启时）
            if ($this->reserveEnabled()) {
                foreach ($invRows as $row) {
                    $this->inventory->releaseReserve(
                        (int) $row['product_id'],
                        (int) $row['warehouse_id'],
                        abs((float) $row['delta'])
                    );
                }
            }

            $doneRows = SaleOrderItem::where('order_id', $id)
                ->whereColumn('outbound_qty', '>=', 'quantity')->count();
            $totalRows = SaleOrderItem::where('order_id', $id)->count();

            $status = ($doneRows >= $totalRows && $totalRows > 0) ? 'done' : 'partial';
            SaleOrder::where('id', $id)->update([
                'status'      => $status,
                'outbound_no' => $doneRows,
            ]);
            Db::commit();

            TradeEvents::publish(TradeEvents::SALE_OUTBOUND, [
                'order_id' => $id,
                'order_no' => $order->order_no,
                'status'   => $status,
                'lines'    => count($invRows),
            ]);
            if ('done' === $status) {
                TradeEvents::publish(TradeEvents::SALE_ORDER_DONE, [
                    'order_id' => $id,
                    'order_no' => $order->order_no,
                ]);
            }
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function close(int $id): bool
    {
        $order = SaleOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['submitted', 'partial', 'done'], true)) {
            throw new \RuntimeException('仅已提交/部分/完成状态可关闭');
        }
        return (bool) SaleOrder::where('id', $id)->update(['status' => 'closed']);
    }

    /**
     * 销售退货（PRD 3.3.3，V_1.1 独立退货单）。
     *
     * 兼容旧调用面：rows = [itemId => 本次退货数量]，内部组装成独立销售退货单(SO-RT)并走全流程。
     * require_check=off 时创建工作即提交并完成（回补库存）；require_check=on 时完成需先验退，
     * 此处创建 + 提交后由验收环节（SaleReturnService::check）决定后续，避免绕过验收直接回补库存。
     *
     * @param array $rows 每项 item_id => 本次退货数量
     */
    public function return(int $id, array $rows, string $operator): int
    {
        $items = [];
        foreach ($rows as $itemId => $qty) {
            $items[] = ['sale_item_id' => $itemId, 'quantity' => $qty];
        }
        $ret = $this->returnSvc->create($id, ['items' => $items], 0);
        $this->returnSvc->submit((int) $ret->id);
        // 仅 require_check=off 时立即完成；on 分支停在已提交，等待验退
        if (!(int) $ret->require_check) {
            $this->returnSvc->complete((int) $ret->id, $operator);
        }
        return (int) $ret->id;
    }

    public function void(int $id, string $reason): bool
    {
        $order = SaleOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['draft', 'submitted'], true)) {
            throw new \RuntimeException('仅草稿/已提交状态可作废');
        }
        $hasOutbound = SaleOrderItem::where('order_id', $id)
            ->where('outbound_qty', '>', 0)->count();
        if ($hasOutbound) {
            throw new \RuntimeException('该订单已有出库记录，无法作废');
        }
        Db::startTrans();
        try {
            // 释放整单预占（配置开启且此前提交预占过）
            if ($this->reserveEnabled()) {
                $this->releaseOrderReserve($order);
            }
            SaleOrder::where('id', $id)->update([
                'status'      => 'voided',
                'void_reason' => $reason,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return true;
    }

    public function detail(int $id): ?array
    {
        $order = SaleOrder::where('id', $id)->find();
        if (!$order) {
            return null;
        }
        $data = $order->toArray();
        $items = SaleOrderItem::where('order_id', $id)->select()->toArray();
        $data['items'] = $this->attachProductNames($items);
        if ($order->customer_id) {
            $data['customer_name'] = Customer::where('id', $order->customer_id)->value('name');
        }
        if ($order->warehouse_id) {
            $data['warehouse_name'] = Warehouse::where('id', $order->warehouse_id)->value('name');
        }
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = SaleOrder::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        if (!empty($cond['order_no'])) {
            $query->whereLike('order_no', "%{$cond['order_no']}%");
        }
        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();

        // 批量补客户名（列表与导出共用）：散单 customer_id 为空时留空，由前端回退展示
        $customerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['customer_id'] ?? 0),
            $list
        ))));
        $names = $customerIds ? Customer::whereIn('id', $customerIds)->column('name', 'id') : [];

        $warehouseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['warehouse_id'] ?? 0),
            $list
        ))));
        $warehouseNames = $warehouseIds ? Warehouse::whereIn('id', $warehouseIds)->column('name', 'id') : [];

        foreach ($list as &$row) {
            $customerId = (int) ($row['customer_id'] ?? 0);
            $row['customer_name'] = $customerId && isset($names[$customerId]) ? (string) $names[$customerId] : '';
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $row['warehouse_name'] = $warehouseId && isset($warehouseNames[$warehouseId])
                ? (string) $warehouseNames[$warehouseId] : '';
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /**
     * 明细补商品名与编号，避免前端只显示商品 ID。
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function attachProductNames(array $items): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (array $it): int => (int) ($it['product_id'] ?? 0),
            $items
        ))));
        if (!$productIds) {
            return $items;
        }
        $products = [];
        foreach (Product::whereIn('id', $productIds)->field('id,name,product_no')->select()->toArray() as $p) {
            $products[(int) $p['id']] = $p;
        }
        foreach ($items as &$item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $item['product_name'] = isset($products[$productId]) ? (string) $products[$productId]['name'] : '';
            $item['product_no']   = isset($products[$productId]) ? (string) $products[$productId]['product_no'] : '';
        }
        unset($item);
        return $items;
    }

    // ---------------- 内部 ----------------

    /**
     * 保存销售明细（V_1.1 价格接入）。
     *
     * 1. 带价解析：手工改价 > 促销价 > 最近成交价 > 等级价 > 参考售价（PRD 3.8）；
     *    未传入 price 时由 PriceRuleService 自动带价。
     * 2. 最低售价审批：解析结果 need_approval=true 时写价格审批留痕（pending），
     *    提交时须 approve 通过（PRD 3.8.1）。
     *
     * @param int         $orderId
     * @param int|null    $customerId 客户 id（散单 null 时不做等级价/最近成交价，仅参考/手工）
     * @param array       $items      每行 product_id/quantity/price?/discount_rate?
     */
    private function saveItems(int $orderId, ?int $customerId, array $items): array
    {
        $amount = 0.0;
        foreach ($items as $it) {
            $qty      = (float) $it['quantity'];
            $productId = (int) $it['product_id'];
            // 带价解析：行未显式给 price 时按优先级自动带价
            $resolved = $this->priceRule->resolvePrice(
                $productId,
                $customerId,
                isset($it['price']) && '' !== $it['price'] ? (float) $it['price'] : null
            );
            $price    = $resolved['price'];

            $discRate = isset($it['discount_rate']) ? (float) $it['discount_rate'] : 0.0;
            $discAmt  = round($qty * $price * $discRate, 2);
            $lineAmt  = round($qty * $price - $discAmt, 2);
            $amount += $lineAmt;

            $rowId = SaleOrderItem::create([
                'order_id'        => $orderId,
                'product_id'      => $productId,
                'quantity'        => $qty,
                'outbound_qty'    => 0,
                'returned_qty'    => 0,
                'price'           => $price,
                'amount'          => $lineAmt,
                // V_1.1 行折扣
                'discount_rate'   => $discRate,
                'discount_amount' => $discAmt,
            ])->id;

            // 低于最低售价 -> 写价格审批留痕（待 trade:price:approve 审批）
            if ($resolved['need_approval']) {
                PriceApproval::create([
                    'sale_order_id'    => $orderId,
                    'sale_order_item_id' => $rowId,
                    'product_id'       => $productId,
                    'applied_price'    => $price,
                    'min_price'        => $resolved['min_price'],
                    'status'           => 'pending',
                ]);
            }
        }
        return ['rows' => count($items), 'amount' => $amount];
    }

    private function assertExists($order): void
    {
        if (!$order) {
            throw new \RuntimeException('销售订单不存在');
        }
    }

    /** 读取租户配置（pack.lin-trade.*），缺失回退默认值。 */
    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** 是否开启提交预占（trade.order.reserve_stock，默认 false）。 */
    private function reserveEnabled(): bool
    {
        return (bool) $this->config('order.reserve_stock', false);
    }

    /** 按整单明细预占库存（进入"已提交"时执行）。 */
    private function reserveForOrder(SaleOrder $order): void
    {
        $items = SaleOrderItem::where('order_id', $order->id)->select()->toArray();
        foreach ($items as $it) {
            $this->inventory->reserveStock(
                (int) $it['product_id'],
                (int) $order->warehouse_id,
                (float) $it['quantity']
            );
        }
    }

    /** 按整单明细释放预占库存（作废/驳回时执行）。 */
    private function releaseOrderReserve(SaleOrder $order): void
    {
        $items = SaleOrderItem::where('order_id', $order->id)->select()->toArray();
        foreach ($items as $it) {
            $this->inventory->releaseReserve(
                (int) $it['product_id'],
                (int) $order->warehouse_id,
                (float) $it['quantity']
            );
        }
    }

    private function genOrderNo(): string
    {
        return $this->docNo->next('SO', 'lg_lin-trade_sale_order', 'order_no');
    }
}