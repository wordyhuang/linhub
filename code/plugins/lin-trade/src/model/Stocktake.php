<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 库存盘点单
 */
class Stocktake extends BaseModel
{
    protected $table = 'lg_lin-trade_stocktake';

    protected $type = [
        'warehouse_id' => 'int',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(StocktakeItem::class, 'stocktake_id');
    }
}