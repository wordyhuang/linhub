<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\PurchaseOrder;
use Lg\Pack\Trade\model\PurchaseOrderItem;
use Lg\Pack\Trade\model\Supplier;
use Lg\Pack\Trade\model\Warehouse;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 采购订单服务。
 *
 * 状态机：draft(草稿) -> submitted(已提交) -> partial/done(部分/已完成入库)，
 * 已提交/进行中单据允许入库，完成后可 closed(关闭)；draft/submitted 且无入库行为时可 voided(作废)。
 */
class PurchaseService
{
    public function __construct(
        private InventoryService $inventory,
        private DocNumberService $docNo,
        private PurchaseReturnService $returnSvc
    ) {
    }

    /**
     * 创建采购订单（草稿）。
     *
     * @param array $data supplier_id/warehouse_id/remark + items[{product_id,quantity,price}]
     * @throws \RuntimeException 供应商、仓库、商品校验失败
     */
    public function create(array $data, int $userId): PurchaseOrder
    {
        if (empty($data['items'])) {
            throw new \RuntimeException('采购订单至少需要一行明细');
        }
        if (empty($data['warehouse_id'])) {
            throw new \RuntimeException('缺少入库仓库');
        }

        Db::startTrans();
        try {
            $order = PurchaseOrder::create([
                'order_no'           => $this->genOrderNo(),
                'supplier_id'        => $data['supplier_id'] ?? null,
                'warehouse_id'       => $data['warehouse_id'],
                'status'             => 'draft',
                'total_amount'       => 0,
                'inbound_no'         => 0,
                'remark'             => $data['remark'] ?? null,
                'expected_arrival_at' => $this->normalizeArrivalDate($data['expected_arrival_at'] ?? null),
                'created_by'         => $userId,
            ]);
            $items = $this->saveItems((int) $order->id, (int) ($order->supplier_id ?? 0), $data['items']);
            $order->total_amount = $items['amount'];
            $order->save();
            Db::commit();
            return $order;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 提交（草稿 -> 已提交） */
    public function submit(int $id): bool
    {
        $order = PurchaseOrder::find($id);
        $this->assertExists($order);
        if ('draft' !== $order->status) {
            throw new \RuntimeException('仅草稿状态可提交，当前：' . $order->status);
        }
        $ok = (bool) PurchaseOrder::where('id', $id)->update(['status' => 'submitted']);
        if ($ok) {
            TradeEvents::publish(TradeEvents::PURCHASE_ORDER_AUDIT, [
                'order_id' => $id,
                'order_no' => $order->order_no,
            ]);
        }
        return $ok;
    }

    /**
     * 采购入库（核心业务）。
     *
     * @param array $rows 每项 item_id => 本次入库数量 received
     */
    public function inbound(int $id, array $rows, string $operator): void
    {
        $order = PurchaseOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['submitted', 'partial', 'done'], true)) {
            throw new \RuntimeException('当前状态不允许入库：' . $order->status);
        }
        if (empty($rows)) {
            throw new \RuntimeException('入库明细不能为空');
        }

        Db::startTrans();
        try {
            $invRows = [];
            foreach ($rows as $itemId => $deltaIn) {
                $item = PurchaseOrderItem::where('order_id', $id)->where('id', $itemId)->find();
                if (!$item) {
                    throw new \RuntimeException('明细不存在：' . $itemId);
                }
                $deltaIn = (float) $deltaIn;
                $remaining = (float) $item->quantity - (float) $item->received_qty;
                if ($deltaIn <= 0 || $deltaIn > $remaining) {
                    throw new \RuntimeException("明细{$itemId}入库数量超出剩余可入库量[{$remaining}]");
                }
                $item->received_qty += $deltaIn;
                $item->save();

                $invRows[] = [
                    'change_type' => InventoryService::TYPE_PURCHASE_IN,
                    'product_id'  => (int) $item->product_id,
                    'warehouse_id' => (int) $order->warehouse_id,
                    'delta'       => $deltaIn,
                    'ref_id'      => $id,
                ];
            }

            $this->inventory->applyMany($invRows, $operator);

            // 重算已入库行数并推进状态
            $doneRows = PurchaseOrderItem::where('order_id', $id)
                ->whereColumn('received_qty', '>=', 'quantity')->count();
            $totalRows = PurchaseOrderItem::where('order_id', $id)->count();

            $status = ($doneRows >= $totalRows && $totalRows > 0) ? 'done' : 'partial';
            PurchaseOrder::where('id', $id)->update([
                'status'     => $status,
                'inbound_no' => $doneRows,
            ]);
            Db::commit();

            TradeEvents::publish(TradeEvents::PURCHASE_INBOUND, [
                'order_id' => $id,
                'order_no' => $order->order_no,
                'status'   => $status,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 采购退货（PRD 3.2.3，V_1.1 独立退货单）。
     *
     * 兼容旧调用面：rows = [itemId => 本次退货数量]，内部组装成独立采购退货单(PO-RT)
     * 并走 草稿→已提交→完成 全流程（完成时扣减库存 + 发布 purchase_return.confirmed）。
     *
     * @param array $rows 每项 item_id => 本次退货数量
     */
    public function return(int $id, array $rows, string $operator): int
    {
        $items = [];
        foreach ($rows as $itemId => $qty) {
            $items[] = ['purchase_item_id' => $itemId, 'quantity' => $qty];
        }
        $ret = $this->returnSvc->create($id, ['items' => $items], 0);
        $this->returnSvc->submit((int) $ret->id);
        $this->returnSvc->complete((int) $ret->id, $operator);
        return (int) $ret->id;
    }

    /** 关闭（已提交/部分/完成 -> 关闭） */
    public function close(int $id): bool
    {
        $order = PurchaseOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['submitted', 'partial', 'done'], true)) {
            throw new \RuntimeException('仅已提交/部分/完成状态可关闭');
        }
        return (bool) PurchaseOrder::where('id', $id)->update(['status' => 'closed']);
    }

    /** 作废（draft/submitted 且无任何入库行为时） */
    public function void(int $id, string $reason): bool
    {
        $order = PurchaseOrder::find($id);
        $this->assertExists($order);
        if (!in_array($order->status, ['draft', 'submitted'], true)) {
            throw new \RuntimeException('仅草稿/已提交状态可作废');
        }
        $hasInbound = PurchaseOrderItem::where('order_id', $id)
            ->where('received_qty', '>', 0)->count();
        if ($hasInbound) {
            throw new \RuntimeException('该订单已有入库记录，无法作废');
        }
        return (bool) PurchaseOrder::where('id', $id)->update([
            'status'      => 'voided',
            'void_reason' => $reason,
        ]);
    }

    public function detail(int $id): ?array
    {
        $order = PurchaseOrder::where('id', $id)->find();
        if (!$order) {
            return null;
        }
        $data = $order->toArray();
        $items = PurchaseOrderItem::where('order_id', $id)->select()->toArray();
        $data['items'] = $this->attachProductNames($items);
        if ($order->supplier_id) {
            $data['supplier_name'] = Supplier::where('id', $order->supplier_id)->value('name');
        }
        if ($order->warehouse_id) {
            $data['warehouse_name'] = Warehouse::where('id', $order->warehouse_id)->value('name');
        }
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = PurchaseOrder::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        if (!empty($cond['order_no'])) {
            $query->whereLike('order_no', "%{$cond['order_no']}%");
        }
        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();

        // 批量补供应商名与仓库名（列表与导出共用）
        $supplierIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['supplier_id'] ?? 0),
            $list
        ))));
        $supplierNames = $supplierIds ? Supplier::whereIn('id', $supplierIds)->column('name', 'id') : [];

        $warehouseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['warehouse_id'] ?? 0),
            $list
        ))));
        $warehouseNames = $warehouseIds ? Warehouse::whereIn('id', $warehouseIds)->column('name', 'id') : [];

        foreach ($list as &$row) {
            $supplierId = (int) ($row['supplier_id'] ?? 0);
            $row['supplier_name'] = $supplierId && isset($supplierNames[$supplierId])
                ? (string) $supplierNames[$supplierId] : '';
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
     * 采购跟踪（PRD 3.2.4）：在途/部分到货/预计到货提醒/超期四类聚合。
     * 消费配置 trade.purchase.arrival_alert_days（预计到货前提醒天数）、trade.purchase.overdue_alert（超期提醒开关）。
     */
    public function track(array $cond = []): array
    {
        $days = (int) $this->config('purchase.arrival_alert_days', 3);
        $overdueAlert = 'on' === (string) $this->config('purchase.overdue_alert', 'on');
        $today = date('Y-m-d');
        $limitAt = date('Y-m-d', strtotime("+{$days} days"));

        $query = PurchaseOrder::with(['items'])
            ->whereIn('status', ['submitted', 'partial'])
            ->order('id', 'desc');
        if (!empty($cond['supplier_id'])) {
            $query->where('supplier_id', (int) $cond['supplier_id']);
        }

        $result = ['transit' => [], 'partial' => [], 'arrival_alert' => [], 'overdue' => []];
        $orders = $query->select();

        // 批量补供应商名，避免跟踪列表只显示供应商 ID
        $supplierIds = [];
        foreach ($orders as $o) {
            /** @var PurchaseOrder $o */
            if (!empty($o->supplier_id)) {
                $supplierIds[(int) $o->supplier_id] = 1;
            }
        }
        $supplierNames = $supplierIds ? Supplier::whereIn('id', array_keys($supplierIds))->column('name', 'id') : [];

        foreach ($orders as $o) {
            /** @var PurchaseOrder $o */
            $supplierId = (int) ($o->supplier_id ?: 0);
            $row = [
                'order_id'            => $o->id,
                'order_no'            => $o->order_no,
                'supplier_id'         => $o->supplier_id,
                'supplier_name'       => $supplierId && isset($supplierNames[$supplierId])
                    ? (string) $supplierNames[$supplierId] : '',
                'status'              => $o->status,
                'expected_arrival_at' => $o->expected_arrival_at,
                'line_count'          => (int) $o->items()->count(),
                'partially_received'  => (int) $o->items()->where('received_qty', '>', 0)->count(),
            ];

            // 超期优先：已超过预计到货日仍未完成
            if ($overdueAlert && !empty($o->expected_arrival_at) && $o->expected_arrival_at < $today) {
                $result['overdue'][] = $row;
                continue;
            }
            // 预计到货提醒：落在 [今天, 今天+days]
            if (!empty($o->expected_arrival_at)
                && $o->expected_arrival_at >= $today && $o->expected_arrival_at <= $limitAt) {
                $result['arrival_alert'][] = $row;
                continue;
            }
            if ('partial' === $o->status) {
                $result['partial'][] = $row;
                continue;
            }
            $result['transit'][] = $row;
        }
        return $result;
    }

    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** 保存明细，返回 ['rows'=>n,'amount'=>总额]。supplier_id 用于进价回填（PRD 3.1.3）。 */
    private function saveItems(int $orderId, ?int $supplierId, array $items): array
    {
        $amount = 0.0;
        foreach ($items as $it) {
            $qty    = (float) $it['quantity'];
            if (isset($it['price']) && '' !== $it['price']) {
                $price = (float) $it['price'];
            } else {
                $price = $this->resolveSupplierPrice((int) $it['product_id'], $supplierId);
            }
            $amount += $qty * $price;
            PurchaseOrderItem::create([
                'order_id'     => $orderId,
                'product_id'   => $it['product_id'],
                'quantity'     => $qty,
                'received_qty' => 0,
                'returned_qty' => 0,
                'price'        => $price,
                'amount'       => $qty * $price,
            ]);
        }
        return ['rows' => count($items), 'amount' => $amount];
    }

    // ---------------- 供应商进价（PRD 3.1.3 最近进价回填 / 近5次入库对比） ----------------

    /**
     * 解析供应商进价：
     *  - 该供应商该商品最近一次入库单价（优先）；
     *  - 无历史时回退商品参考进价 reference_cost_price。
     */
    private function resolveSupplierPrice(int $productId, ?int $supplierId): float
    {
        if ('on' === (string) $this->config('supplier.recent_price_backfill', 'on')) {
            $recent = $this->recentSupplierPrice($productId, $supplierId);
            if (null !== $recent) {
                return round($recent, 4);
            }
        }
        return round((float) Product::where('id', $productId)->value('reference_cost_price'), 4);
    }

    /**
     * 该供应商对该商品最近一次入库单价（近进价回填）。
     * 依据：该供应商采购单该商品已入库(received_qty>0)的行，取最近一张采购单的入库行单价。
     */
    public function recentSupplierPrice(int $productId, ?int $supplierId): ?float
    {
        if (!$supplierId) {
            return null;
        }
        $price = Db::name('lg_lin-trade_purchase_order_item')
            ->alias('i')
            ->join('lg_lin-trade_purchase_order o', 'o.id = i.order_id')
            ->where('o.supplier_id', $supplierId)
            ->where('i.product_id', $productId)
            ->where('i.received_qty', '>', 0)
            ->order('o.id', 'desc')
            ->value('i.price');
        return null === $price ? null : (float) $price;
    }

    /**
     * 进价对比（PRD 3.1.3：近 5 次入库的 供应商/日期/单价/数量 横向对比）。
     * 低于历史最低进价仅提示不拦截。
     *
     * @return array{list:array, lowest:float, current:?float, below_lowest:bool}
     */
    public function priceCompare(int $productId, ?int $supplierId, int $limit = 5): array
    {
        $query = Db::name('lg_lin-trade_purchase_order_item')
            ->alias('i')
            ->join('lg_lin-trade_purchase_order o', 'o.id = i.order_id')
            ->field('o.supplier_id, i.product_id, i.price, i.received_qty, i.updated_at')
            ->where('i.product_id', $productId)
            ->where('i.received_qty', '>', 0)
            ->order('i.updated_at', 'desc')
            ->limit($limit);

        if ($supplierId) {
            $query->where('o.supplier_id', $supplierId);
        }

        $list = $query->select()->toArray();
        foreach ($list as &$row) {
            $row['supplier_name'] = $row['supplier_id']
                ? Supplier::where('id', $row['supplier_id'])->value('name') : '';
            $row['price']   = (float) $row['price'];
            $row['received_qty'] = (float) $row['received_qty'];
        }
        unset($row);

        $prices = array_map(fn($r) => $r['price'], $list);
        $lowest = $prices ? min($prices) : 0.0;
        $current = null;
        if ($supplierId) {
            $current = $this->recentSupplierPrice($productId, $supplierId);
        }

        return [
            'list'          => $list,
            'lowest'        => $lowest,
            'current'       => $current,
            'below_lowest'  => (null !== $current && $current < $lowest && $current > 0),
        ];
    }

    private function assertExists($order): void
    {
        if (!$order) {
            throw new \RuntimeException('采购订单不存在');
        }
    }

    /** 归一化预计到货日期：空值/空串置 null，否则原样存（字段为 datetime）。 */
    private function normalizeArrivalDate($val): ?string
    {
        if (null === $val || '' === $val) {
            return null;
        }
        $date = trim((string) $val);
        return '' === $date ? null : $date;
    }

    private function genOrderNo(): string
    {
        return $this->docNo->next('PO', 'lg_lin-trade_purchase_order', 'order_no');
    }
}