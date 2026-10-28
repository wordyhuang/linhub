<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 实时库存
 */
class Inventory extends BaseModel
{
    protected $table = 'lg_lin-trade_inventory';

    protected $type = [
        'product_id'  => 'int',
        'warehouse_id' => 'int',
        'quantity'    => 'float',
        // V_1.1 移动加权成本
        'weighted_cost' => 'float',
    ];

    /** 关联商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** 关联仓库 */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}