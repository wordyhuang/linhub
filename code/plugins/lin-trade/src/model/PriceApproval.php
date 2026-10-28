<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 价格审批留痕（PRD 3.8.1）
 */
class PriceApproval extends BaseModel
{
    protected $table = 'lg_lin-trade_price_approval';

    protected $type = [
        'sale_order_id'    => 'int',
        'sale_order_item_id'=> 'int',
        'product_id'       => 'int',
        'applied_price'    => 'float',
        'min_price'        => 'float',
        'approver_id'      => 'int',
        'created_by'       => 'int',
    ];

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}