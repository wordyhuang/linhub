<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 销售退货明细
 */
class SaleReturnItem extends BaseModel
{
    protected $table = 'lg_lin-trade_sale_return_item';

    protected $type = [
        'return_id'        => 'int',
        'sale_order_item_id' => 'int',
        'product_id'       => 'int',
        'quantity'         => 'float',
        'price'            => 'float',
        'amount'           => 'float',
    ];

    /** 主单 */
    public function returnOrder()
    {
        return $this->belongsTo(SaleReturn::class, 'return_id');
    }

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}