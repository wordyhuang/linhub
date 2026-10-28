<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 采购订单
 */
class PurchaseOrder extends BaseModel
{
    protected $table = 'lg_lin-trade_purchase_order';

    protected $type = [
        'supplier_id'  => 'int',
        'warehouse_id' => 'int',
        'total_amount' => 'float',
        'inbound_no'   => 'int',
        'created_by'   => 'int',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'order_id');
    }

    /** 供应商 */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}