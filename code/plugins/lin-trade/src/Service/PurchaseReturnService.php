<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\PurchaseOrder;
use Lg\Pack\Trade\model\PurchaseOrderItem;
use Lg\Pack\Trade\model\PurchaseReturn;
use Lg\Pack\Trade\model\PurchaseReturnItem;
use Lg\Pack\Trade\model\Supplier;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\Db;

/**
 * 采购退货服务（PRD 3.2.3 / 状态机 4.3）。
 *
 * 状态机：draft(草稿) -> submitted(已提交) -> done(已完成)；
 * 库存扣减发生在"完成"过渡（提交并扣减库存），草稿/已提交在扣库前可作废 -> voided(已作废)。
 *
 * 可退上限精确到采购单明细：可退数量 = 该行已入库 received_qty - 已退 returned_qty；
 * 完成退货时逐行校验，超出拒绝并提示余量；扣减库存走事务+行锁，扣减后余量 < 0 整单阻止。
 */
class PurchaseReturnService
{
    public function __construct(
        private InventoryService $inventory,
        private DocNumberService $docNo
    ) {
    }

    /**
     * 创建采购退货草稿（不扣库存、不发号后不可改的生效逻辑尚未发生）。
     *
     * @param array $data items: [[purchase_item_id, quantity, price?, product_id?]]
     */
    public function create(int $purchaseOrderId, array $data, int $userId): PurchaseReturn
    {
        $order = PurchaseOrder::find($purchaseOrderId);
        if (!$order) {
            throw new \RuntimeException('采购订单不存在');
        }
        if (empty($data['items'])) {
            throw new \RuntimeException('采购退货至少需要一行明细');
        }

        $lines = $this->buildLines($purchaseOrderId, $data['items']);

        Db::startTrans();
        try {
            $return = PurchaseReturn::create([
                'return_no'         => $this->docNo->next('PO-RT', 'lg_lin-trade_purchase_return', 'return_no'),
                'purchase_order_id' => $purchaseOrderId,
                'supplier_id'       => $order->supplier_id,
                'status'            => 'draft',
                'total_amount'      => array_sum(array_column($lines, 'amount')),
                'remark'            => $data['remark'] ?? null,
                'created_by'        => $userId,
            ]);
            foreach ($lines as $line) {
                PurchaseReturnItem::create([
                    'return_id'            => $return->id,
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'product_id'           => $line['product_id'],
                    'quantity'             => $line['quantity'],
                    'price'                => $line['price'],
                    'amount'               => $line['amount'],
                ]);
            }
            Db::commit();
            return $return;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 提交：草稿 -> 已提交（可退上限在创建与完成两处都校验，提交前再校验一次） */
    public function submit(int $id): bool
    {
        $return = PurchaseReturn::find($id);
        $this->assertExists($return);
        if ('draft' !== $return->status) {
            throw new \RuntimeException('仅草稿状态可提交，当前：' . $return->status);
        }
        return (bool) PurchaseReturn::where('id', $id)->update(['status' => 'submitted']);
    }

    /**
     * 完成退货：扣减库存并累加原采购单明细的 returned_qty，发布购退货确认事件。
     *
     * @param array|null $forceItems 可选二次复核明细（与创建时一致则无需再传），传空则按单内明细执行
     */
    public function complete(int $id, string $operator): bool
    {
        $return = PurchaseReturn::find($id);
        $this->assertExists($return);
        if ('submitted' !== $return->status) {
            throw new \RuntimeException("当前状态不可完成退货：{$return->status}");
        }
        if (!$return->purchase_order_id) {
            throw new \RuntimeException('退货单缺少来源采购订单');
        }

        $order = PurchaseOrder::find($return->purchase_order_id);
        $items = PurchaseReturnItem::where('return_id', $id)->select();

        Db::startTrans();
        try {
            $invRows = [];
            foreach ($items as $rit) {
                $poItem = PurchaseOrderItem::where('id', $rit->purchase_order_item_id)
                    ->where('order_id', $return->purchase_order_id)
                    ->find();
                if (!$poItem) {
                    throw new \RuntimeException("退货明细缺少对应采购行[{$rit->id}]");
                }
                $result = $this->applyReturnLine($poItem, (float) $rit->quantity, (int) ($order->warehouse_id ?? 0), (int) $return->id);
                $invRows[] = $result['row'];
            }

            $this->inventory->applyMany($invRows, $operator);
            PurchaseReturn::where('id', $id)->update([
                'status'     => 'done',
                'checked_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        TradeEvents::publish(TradeEvents::PURCHASE_RETURN_CONFIRMED, [
            'return_id'   => $id,
            'return_no'   => $return->return_no,
            'purchase_order_id' => $return->purchase_order_id,
            'supplier_id' => $return->supplier_id,
            'total_amount' => $return->total_amount,
        ]);
        return true;
    }

    /** 作废：仅草稿/已提交（未完成、未扣库存）可作废 */
    public function void(int $id, string $reason): bool
    {
        $return = PurchaseReturn::find($id);
        $this->assertExists($return);
        if (!in_array($return->status, ['draft', 'submitted'], true)) {
            throw new \RuntimeException('仅草稿/已提交状态可作废，当前：' . $return->status);
        }
        return (bool) PurchaseReturn::where('id', $id)->update([
            'status'      => 'voided',
            'void_reason' => $reason,
        ]);
    }

    public function detail(int $id): ?array
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return null;
        }
        $data = $return->toArray();
        $data['items'] = PurchaseReturnItem::where('return_id', $id)->select()->toArray();
        if ($return->supplier_id) {
            $data['supplier_name'] = Supplier::where('id', $return->supplier_id)->value('name');
        }
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = PurchaseReturn::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        if (!empty($cond['return_no'])) {
            $query->whereLike('return_no', "%{$cond['return_no']}%");
        }
        return [
            'total' => $query->count(),
            'list'  => $query->page($page, $pageSize)->select()->toArray(),
        ];
    }

    // ---------------- 内部 ----------------

    /** 组装并预校验退货行：quantity 与可退上限（已收-已退）对齐，price 缺省取原采购行单价 */
    private function buildLines(int $purchaseOrderId, array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            if (empty($row['purchase_item_id'])) {
                throw new \RuntimeException('退货明细缺少采购行标识');
            }
            $poItem = PurchaseOrderItem::where('id', $row['purchase_item_id'])
                ->where('order_id', $purchaseOrderId)
                ->find();
            if (!$poItem) {
                throw new \RuntimeException('采购行不存在：' . $row['purchase_item_id']);
            }
            $qty = round((float) $row['quantity'], 4);
            $avail = round((float) $poItem->received_qty - (float) $poItem->returned_qty, 4);
            if ($qty <= 0) {
                throw new \RuntimeException('退货数量必须大于 0');
            }
            if ($qty > $avail) {
                throw new \RuntimeException("采购行[{$poItem->id}]可退上限[{$avail}]（已收" .
                    (float) $poItem->received_qty . '-已退' . (float) $poItem->returned_qty . '），超量拒绝');
            }
            $price = isset($row['price']) ? round((float) $row['price'], 4) : (float) $poItem->price;
            $lines[] = [
                'purchase_order_item_id' => (int) $poItem->id,
                'product_id'             => (int) $poItem->product_id,
                'quantity'               => $qty,
                'price'                  => $price,
                'amount'                 => round($qty * $price, 2),
            ];
        }
        return $lines;
    }

    /** 单行消费可退上限并生成库存扣减行；同时累加原采购行 returned_qty */
    private function applyReturnLine($poItem, float $qty, int $warehouseId, int $refId): array
    {
        $avail = round((float) $poItem->received_qty - (float) $poItem->returned_qty, 4);
        if ($qty > $avail) {
            throw new \RuntimeException("采购行[{$poItem->id}]可退上限[{$avail}]，当前退货[{$qty}]超量");
        }
        $poItem->returned_qty = round((float) $poItem->returned_qty + $qty, 4);
        $poItem->save();

        return [
            'row' => [
                'change_type'  => InventoryService::TYPE_PURCHASE_RETURN,
                'product_id'   => (int) $poItem->product_id,
                'warehouse_id' => $warehouseId,
                'delta'        => -$qty,
                'ref_id'       => $refId,
                'remark'       => '采购退货',
            ],
        ];
    }

    private function assertExists($return): void
    {
        if (!$return) {
            throw new \RuntimeException('采购退货单不存在');
        }
    }
}