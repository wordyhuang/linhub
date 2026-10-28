<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 销售订单明细
 */
class SaleOrderItem extends BaseModel
{
    protected $table = 'lg_lin-trade_sale_order_item';

    protected $type = [
        'order_id'      => 'int',
        'product_id'    => 'int',
        'quantity'      => 'float',
        'outbound_qty'  => 'float',
        'returned_qty'  => 'float',
        'price'         => 'float',
        'amount'        => 'float',
        // V_1.1 行折扣
        'discount_rate'   => 'float',
        'discount_amount' => 'float',
    ];

    /** 主单 */
    public function order()
    {
        return $this->belongsTo(SaleOrder::class, 'order_id');
    }

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}