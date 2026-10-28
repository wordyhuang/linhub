<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 采购退货单
 */
class PurchaseReturn extends BaseModel
{
    protected $table = 'lg_lin-trade_purchase_return';

    protected $type = [
        'purchase_order_id' => 'int',
        'supplier_id'       => 'int',
        'total_amount'      => 'float',
        'created_by'        => 'int',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
    }

    /** 供应商 */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}