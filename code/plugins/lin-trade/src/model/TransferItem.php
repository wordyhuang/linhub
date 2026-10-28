<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 调拨明细
 */
class TransferItem extends BaseModel
{
    protected $table = 'lg_lin-trade_transfer_item';

    protected $type = [
        'transfer_id' => 'int',
        'product_id'  => 'int',
        'quantity'    => 'float',
    ];

    /** 主单 */
    public function transfer()
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }
}