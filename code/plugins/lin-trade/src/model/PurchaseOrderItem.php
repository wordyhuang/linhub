<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 采购订单明细
 */
class PurchaseOrderItem extends BaseModel
{
    protected $table = 'lg_lin-trade_purchase_order_item';

    protected $type = [
        'order_id'     => 'int',
        'product_id'   => 'int',
        'quantity'     => 'float',
        'received_qty' => 'float',
        'returned_qty' => 'float',
        'price'        => 'float',
        'amount'       => 'float',
    ];

    /** 主单 */
    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}