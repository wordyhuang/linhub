<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 采购退货明细
 */
class PurchaseReturnItem extends BaseModel
{
    protected $table = 'lg_lin-trade_purchase_return_item';

    protected $type = [
        'return_id'            => 'int',
        'purchase_order_item_id' => 'int',
        'product_id'           => 'int',
        'quantity'             => 'float',
        'price'                => 'float',
        'amount'               => 'float',
    ];

    /** 主单 */
    public function returnOrder()
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}