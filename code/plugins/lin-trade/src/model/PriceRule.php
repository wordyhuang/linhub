<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 价格规则（等级价/促销价/最低售价）
 */
class PriceRule extends BaseModel
{
    protected $table = 'lg_lin-trade_price_rule';

    protected $type = [
        'product_id'  => 'int',
        'level_price' => 'float',
        'min_price'   => 'float',
    ];

    /** 商品 */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}