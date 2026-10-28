<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Transfer;
use Lg\Pack\Trade\model\TransferItem;
use think\facade\Db;

/**
 * 库存调拨服务。
 *
 * 状态机：submitted(已提交) -> done(已完成)。
 * 调拨完成时：调出仓库扣减、调入仓库增加，两条库存流水同事务，负库存红线由 InventoryService 强制。
 */
class TransferService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function create(int $fromWarehouseId, int $toWarehouseId, array $items,
                           ?string $operator = null, ?string $remark = null): Transfer
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new \RuntimeException('调出与调入仓库不能相同');
        }
        if (empty($items)) {
            throw new \RuntimeException('调拨单至少需要一行明细');
        }

        Db::startTrans();
        try {
            $transfer = Transfer::create([
                'transfer_no'       => $this->genNo(),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id'   => $toWarehouseId,
                'status'            => 'submitted',
                'operator'          => $operator,
                'remark'            => $remark,
            ]);
            foreach ($items as $it) {
                $qty = (float) $it['quantity'];
                if ($qty <= 0) {
                    throw new \RuntimeException('调拨数量必须大于0');
                }
                TransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id'  => $it['product_id'],
                    'quantity'    => $qty,
                ]);
            }
            Db::commit();
            return $transfer;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 完成调拨：出库入库成对执行 */
    public function complete(int $transferId, string $operator): void
    {
        $t = Transfer::find($transferId);
        $this->assertExists($t);
        if ('done' === $t->status) {
            throw new \RuntimeException('调拨单已完成，不可重复执行');
        }

        Db::startTrans();
        try {
            $rows = [];
            foreach (TransferItem::where('transfer_id', $transferId)->select() as $item) {
                $qty = (float) $item->quantity;
                $rows[] = [
                    'change_type' => InventoryService::TYPE_TRANSFER_OUT,
                    'product_id'  => (int) $item->product_id,
                    'warehouse_id' => (int) $t->from_warehouse_id,
                    'delta'       => -$qty,
                    'ref_id'      => $transferId,
                    'remark'      => '调拨出库',
                ];
                $rows[] = [
                    'change_type' => InventoryService::TYPE_TRANSFER_IN,
                    'product_id'  => (int) $item->product_id,
                    'warehouse_id' => (int) $t->to_warehouse_id,
                    'delta'       => $qty,
                    'ref_id'      => $transferId,
                    'remark'      => '调拨入库',
                ];
            }
            $this->inventory->applyMany($rows, $operator);
            Transfer::where('id', $transferId)->update(['status' => 'done']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function detail(int $transferId): ?array
    {
        $t = Transfer::where('id', $transferId)->find();
        if (!$t) {
            return null;
        }
        $data = $t->toArray();
        $data['items'] = TransferItem::where('transfer_id', $transferId)->select()->toArray();
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = Transfer::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();
        return ['total' => $total, 'list' => $list];
    }

    private function assertExists($transfer): void
    {
        if (!$transfer) {
            throw new \RuntimeException('调拨单不存在');
        }
    }

    private function genNo(): string
    {
        return 'TF' . date('YmdHis') . mt_rand(1000, 9999);
    }
}