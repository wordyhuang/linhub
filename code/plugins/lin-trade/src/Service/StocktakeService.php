<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Stocktake;
use Lg\Pack\Trade\model\StocktakeItem;
use think\facade\Db;

/**
 * 库存盘点服务。
 *
 * 状态机：taking(盘点中) -> confirmed(已确认) -> adjusted(已调整)。
 * 生成盘点单时预填账面数量；确认后记录差异；调整时按差异写入库存流水。
 */
class StocktakeService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * 新建盘点单（taking），可按商品列表限定，未指定则纳入全部在库商品。
     */
    public function create(int $warehouseId, ?array $productIds = [], ?string $operator = null, ?string $remark = null): Stocktake
    {
        Db::startTrans();
        try {
            $stocktake = Stocktake::create([
                'stocktake_no' => $this->genNo(),
                'warehouse_id' => $warehouseId,
                'status'       => 'taking',
                'operator'     => $operator,
                'remark'       => $remark,
            ]);

            $query = Db::name('lg_lin-trade_inventory')
                ->alias('i')
                ->join('lg_lin-trade_product p', 'p.id = i.product_id')
                ->where('i.warehouse_id', $warehouseId)
                ->where('p.status', 1)
                ->field('i.product_id, i.quantity as book_qty');

            if (!empty($productIds)) {
                $query->whereIn('i.product_id', $productIds);
            }

            foreach ($query->select() as $row) {
                StocktakeItem::create([
                    'stocktake_id' => $stocktake->id,
                    'product_id'   => (int) $row['product_id'],
                    'book_qty'     => (float) $row['book_qty'],
                    'actual_qty'   => (float) $row['book_qty'],
                    'diff_qty'     => 0,
                ]);
            }
            Db::commit();
            return $stocktake;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 录入实盘数量（盘点中状态） */
    public function recordActual(int $stocktakeId, array $actualMap): void
    {
        $st = Stocktake::find($stocktakeId);
        $this->assertExists($st);
        if ('taking' !== $st->status) {
            throw new \RuntimeException('仅盘点中可录入实盘');
        }
        foreach ($actualMap as $itemId => $actual) {
            StocktakeItem::where('stocktake_id', $stocktakeId)
                ->where('id', $itemId)
                ->update([
                    'actual_qty' => $actual,
                    'diff_qty'   => (float) $actual - Db::name('lg_lin-trade_stocktake_item')
                        ->where('id', $itemId)->value('book_qty'),
                ]);
        }
    }

    public function confirm(int $stocktakeId): bool
    {
        $st = Stocktake::find($stocktakeId);
        $this->assertExists($st);
        if ('taking' !== $st->status) {
            throw new \RuntimeException('仅盘点中可确认');
        }
        return (bool) Stocktake::where('id', $stocktakeId)->update(['status' => 'confirmed']);
    }

    /**
     * 确认并调整库存（confirmed -> adjusted）。
     * 按差异 diff_qty 写入库存变动：正则入库、负则出库，负库存红线由 InventoryService 强制。
     */
    public function adjust(int $stocktakeId, string $operator): void
    {
        $st = Stocktake::find($stocktakeId);
        $this->assertExists($st);
        if ('confirmed' !== $st->status) {
            throw new \RuntimeException('仅已确认可调整库存');
        }

        Db::startTrans();
        try {
            $rows = [];
            foreach (StocktakeItem::where('stocktake_id', $stocktakeId)->select() as $item) {
                $diff = (float) $item->diff_qty;
                if (0.0 == $diff) {
                    continue;
                }
                $rows[] = [
                    'change_type' => InventoryService::TYPE_STOCKTAKE,
                    'product_id'  => (int) $item->product_id,
                    'warehouse_id' => (int) $st->warehouse_id,
                    'delta'       => $diff,
                    'ref_id'      => $stocktakeId,
                    'remark'      => '盘点调整',
                ];
            }
            $this->inventory->applyMany($rows, $operator);
            Stocktake::where('id', $stocktakeId)->update(['status' => 'adjusted']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function detail(int $stocktakeId): ?array
    {
        $st = Stocktake::where('id', $stocktakeId)->find();
        if (!$st) {
            return null;
        }
        $data = $st->toArray();
        $data['items'] = StocktakeItem::where('stocktake_id', $stocktakeId)->select()->toArray();
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = Stocktake::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();
        return ['total' => $total, 'list' => $list];
    }

    private function assertExists($stocktake): void
    {
        if (!$stocktake) {
            throw new \RuntimeException('盘点单不存在');
        }
    }

    private function genNo(): string
    {
        return 'ST' . date('YmdHis') . mt_rand(1000, 9999);
    }
}