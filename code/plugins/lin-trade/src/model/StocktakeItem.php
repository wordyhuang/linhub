<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 盘点明细
 */
class StocktakeItem extends BaseModel
{
    protected $table = 'lg_lin-trade_stocktake_item';

    protected $type = [
        'stocktake_id' => 'int',
        'product_id'   => 'int',
        'book_qty'     => 'float',
        'actual_qty'   => 'float',
        'diff_qty'     => 'float',
    ];

    /** 主单 */
    public function stocktake()
    {
        return $this->belongsTo(Stocktake::class, 'stocktake_id');
    }
}