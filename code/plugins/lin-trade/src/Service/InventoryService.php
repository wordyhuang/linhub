<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Inventory;
use Lg\Pack\Trade\model\InventoryLog;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\Warehouse;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\Db;

/**
 * 库存核心服务（宿主服务：trade.inventory）。
 *
 * 职责：提供跨包/跨产品可调用的库存查询与变动能力。
 * 变动必须成对写 lg_lin-trade_inventory + lg_lin-trade_inventory_log，并保持事务一致。
 *
 * 安全红线：库存数量在任何扣减场景下不得出现负数（P0）。扣减前校验，扣减后复核。
 */
class InventoryService
{
    /** 变动类型常量（与 inventory_log.change_type 字段枚举一致） */
    public const TYPE_PURCHASE_IN   = 'purchase_in';   // 采购入库
    public const TYPE_SALE_OUT      = 'sale_out';      // 销售出库
    public const TYPE_RETURN_IN     = 'return_in';     // 客户退货入库
    public const TYPE_RETURN_OUT    = 'return_out';    // 采购退货出库
    public const TYPE_STOCKTAKE     = 'stocktake';     // 盘点调整
    public const TYPE_TRANSFER_IN   = 'transfer_in';   // 调拨入库
    public const TYPE_TRANSFER_OUT  = 'transfer_out';  // 调拨出库
    // V_1.1 新增变动来源（PRD 3.4.2 枚举扩充，不改变既有取值）
    public const TYPE_INITIAL        = 'initial';        // 期初库存导入
    public const TYPE_PURCHASE_RETURN = 'purchase_return'; // 采购退货扣减
    public const TYPE_SALE_RETURN    = 'sale_return';    // 销售退货回补
    public const TYPE_RECONCILE_ADJUST = 'reconcile_adjust'; // 对账校正

    /**
     * 查询某商品在指定仓库的实时库存。
     *
     * @return float|int 不存在或未建档时返回 0
     */
    public function quantity(int $productId, int $warehouseId): float
    {
        $inv = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->find();
        return $inv ? (float) $inv->quantity : 0.0;
    }

    /**
     * 汇总某商品在所有仓库的合计库存。
     */
    public function totalQuantity(int $productId): float
    {
        return (float) Inventory::where('product_id', $productId)->sum('quantity');
    }

    /**
     * 定义库存变动（入/出）。调用方需自行包裹在业务事务内。
     *
     * 规则：
     *  - changeQty > 0 为入库，< 0 为出库。
     *  - 出库（changeQty < 0）前校验现有库存 - 出库量 >= 0，否则抛异常（负库存红线）。
     *  - 无库存档案时自动插入（行不存在则创建）。
     *
     * @throws \RuntimeException 当扣减会致负库存时
     */
    public function apply(string $changeType, int $productId, int $warehouseId, float $delta,
                          ?int $refId = null, ?string $operator = null, ?string $remark = null,
                          ?float $unitCost = null): void
    {
        if (0.0 == $delta) {
            return;
        }

        Db::startTrans();
        try {
            // 锁定库存行，避免并发超卖
            $inv = Inventory::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lock(true)
                ->find();

            $before = $inv ? (float) $inv->quantity : 0.0;
            $after  = $before + $delta;

            // 出库场景负库存红线
            if ($after < 0) {
                throw new \RuntimeException(
                    sprintf('库存不足：商品[%d] 仓库[%d] 当前[%.4f]，需变动[%.4f]',
                        $productId, $warehouseId, $before, $delta)
                );
            }

            // 加权成本计算（PRD 3.5 毛利 weighted 口径）：仅入库且提供单价时更新
            $weightedCost = $inv ? (float) $inv->weighted_cost : 0.0;
            if ($unitCost !== null && $delta > 0) {
                $weightedCost = ($before >= 0)
                    ? (($before * $weightedCost) + ($delta * $unitCost)) / ($before + $delta)
                    : $unitCost;
            }

            if ($inv) {
                $inv->quantity     = $after;
                $inv->weighted_cost = $this->round4($weightedCost);
                $inv->save();
            } else {
                Inventory::create([
                    'product_id'    => $productId,
                    'warehouse_id'  => $warehouseId,
                    'quantity'      => $after,
                    'weighted_cost' => $this->round4($weightedCost),
                ]);
            }

            InventoryLog::create([
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
                'change_type'  => $changeType,
                'ref_id'       => $refId,
                'before_qty'   => $before,
                'change_qty'   => $delta,
                'after_qty'    => $after,
                'operator'     => $operator,
                'remark'       => $remark,
            ]);

            Db::commit();

            // 事务成功后广播库存变动事件
            TradeEvents::publish(TradeEvents::INVENTORY_CHANGED, [
                'change_type' => $changeType,
                'product_id'  => $productId,
                'warehouse_id' => $warehouseId,
                'delta'       => $delta,
                'after'       => $after,
                'ref_id'      => $refId,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 批量定义变动（用于多行单据），整体事务。
     *
     * @param array $rows 每项含 change_type/product_id/warehouse_id/delta/ref_id/remark/unit_cost
     */
    public function applyMany(array $rows, ?string $operator = null): void
    {
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $this->apply(
                    $row['change_type'],
                    $row['product_id'],
                    $row['warehouse_id'],
                    (float) $row['delta'],
                    $row['ref_id'] ?? null,
                    $operator,
                    $row['remark'] ?? null,
                    isset($row['unit_cost']) ? (float) $row['unit_cost'] : null
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 期初库存导入（V_1.1，PRD 3.6）。
     * 商品已存在任何库存流水（非 initial）时禁止导入，提示走盘点校正。
     *
     * @param array $rows 每项 product_id/warehouse_id/quantity/remark（单位可含 unit_cost）
     * @return int 已导入行数
     */
    public function importInitial(array $rows, string $operator): int
    {
        if (empty($rows)) {
            throw new \RuntimeException('期初库存导入无有效数据');
        }
        // 红线：有非期初流水（历史业务变动）的商品禁止导期初
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $exist = InventoryLog::where('product_id', $productId)
                ->where('change_type', '<>', self::TYPE_INITIAL)
                ->count();
            if ($exist > 0) {
                throw new \RuntimeException("商品[{$productId}]已有业务库存流水，不能导期初，请走盘点校正");
            }
        }

        Db::startTrans();
        try {
            $imported = 0;
            foreach ($rows as $row) {
                $productId   = (int) $row['product_id'];
                $warehouseId = (int) $row['warehouse_id'];
                $qty         = (float) $row['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                // 期初视为入库（正量），可携带期初成本
                $inv = Inventory::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->lock(true)
                    ->find();
                if ($inv && (float) $inv->quantity > 0) {
                    throw new \RuntimeException("商品[{$productId}]仓库[{$warehouseId}]已有库存，禁止重复导入期初");
                }
                $this->apply(
                    self::TYPE_INITIAL, $productId, $warehouseId, $qty,
                    null, $operator,
                    $row['remark'] ?? '期初库存导入',
                    isset($row['unit_cost']) ? (float) $row['unit_cost'] : null
                );
                $imported++;
            }
            Db::commit();
            return $imported;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 四舍五入保留 4 位小数（数量/成本口径） */
    private function round4(float $v): float
    {
        return round($v, 4);
    }

    /**
     * 库存预警查询：返回库存低于安全库存的商品清单。
     *
     * @return array{product_id:int, name:string, product_no:string, quantity:float, safety_stock:float}[]
     */
    public function lowStockList(?int $warehouseId = null, int $limit = 100): array
    {
        $query = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereColumn('i.quantity', '<', 'p.safety_stock')
            ->where('p.status', 1);

        if ($warehouseId) {
            $query->where('i.warehouse_id', $warehouseId);
        }

        return $query->field('i.product_id, p.name, p.product_no, p.safety_stock, i.quantity')
            ->order('p.name')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 某商品全部库存流水（按时间倒序）。
     */
    public function logs(int $productId, ?int $warehouseId = null, string $changeType = '', int $limit = 200): array
    {
        $query = InventoryLog::where('product_id', $productId);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ('' !== $changeType) {
            $query->where('change_type', $changeType);
        }
        $list = $query->order('id', 'desc')->limit($limit)->select()->toArray();

        // 补仓库名，避免流水表只显示仓库 ID
        $warehouseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['warehouse_id'] ?? 0),
            $list
        ))));
        $warehouseNames = $warehouseIds ? Warehouse::whereIn('id', $warehouseIds)->column('name', 'id') : [];
        foreach ($list as &$row) {
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $row['warehouse_name'] = $warehouseId && isset($warehouseNames[$warehouseId])
                ? (string) $warehouseNames[$warehouseId] : '';
        }
        unset($row);

        // 补来源单据号（溯源链接用）：ref_id 语义随 change_type 不同，按类型分组批量查询
        $docTypeMap = [
            self::TYPE_PURCHASE_IN     => ['lg_lin-trade_purchase_order', 'order_no'],
            self::TYPE_RETURN_OUT      => ['lg_lin-trade_purchase_order', 'order_no'],   // 旧枚举：采购退货关联采购单
            self::TYPE_SALE_OUT        => ['lg_lin-trade_sale_order', 'order_no'],
            self::TYPE_RETURN_IN       => ['lg_lin-trade_sale_order', 'order_no'],       // 旧枚举：销售退货关联销售单
            self::TYPE_SALE_RETURN     => ['lg_lin-trade_sale_return', 'return_no'],
            self::TYPE_PURCHASE_RETURN => ['lg_lin-trade_purchase_return', 'return_no'],
            self::TYPE_STOCKTAKE       => ['lg_lin-trade_stocktake', 'stocktake_no'],
            self::TYPE_TRANSFER_IN     => ['lg_lin-trade_transfer', 'transfer_no'],
            self::TYPE_TRANSFER_OUT    => ['lg_lin-trade_transfer', 'transfer_no'],
            // initial / reconcile_adjust 无来源单据，留空
        ];
        $groups = [];
        foreach ($list as $i => $row) {
            $ct = (string) ($row['change_type'] ?? '');
            if (isset($docTypeMap[$ct]) && (int) ($row['ref_id'] ?? 0) > 0) {
                $groups[$ct][(int) $row['ref_id']][] = $i;
            }
        }
        foreach ($groups as $ct => $refs) {
            [$table, $col] = $docTypeMap[$ct];
            $noMap = Db::name($table)->whereIn('id', array_keys($refs))->column($col, 'id');
            foreach ($refs as $rid => $idxList) {
                foreach ($idxList as $i) {
                    $list[$i]['ref_no'] = (string) ($noMap[$rid] ?? '');
                }
            }
        }
        foreach ($list as &$row) {
            $row['ref_no'] = (string) ($row['ref_no'] ?? '');
        }
        unset($row);

        return $list;
    }
}