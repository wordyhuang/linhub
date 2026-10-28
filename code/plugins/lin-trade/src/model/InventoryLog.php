<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 库存流水
 */
class InventoryLog extends BaseModel
{
    protected $table = 'lg_lin-trade_inventory_log';

    protected $type = [
        'product_id'   => 'int',
        'warehouse_id' => 'int',
        'ref_id'       => 'int',
        'before_qty'   => 'float',
        'change_qty'   => 'float',
        'after_qty'    => 'float',
    ];
}