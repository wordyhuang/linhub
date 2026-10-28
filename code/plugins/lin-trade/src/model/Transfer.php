<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 库存调拨单
 */
class Transfer extends BaseModel
{
    protected $table = 'lg_lin-trade_transfer';

    protected $type = [
        'from_warehouse_id' => 'int',
        'to_warehouse_id'   => 'int',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(TransferItem::class, 'transfer_id');
    }
}