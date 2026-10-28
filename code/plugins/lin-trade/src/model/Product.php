<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 商品档案
 */
class Product extends BaseModel
{
    protected $table = 'lg_lin-trade_product';

    protected $type = [
        'unit_id'       => 'int',
        'category_id'   => 'int',
        'is_production' => 'int',
        'status'        => 'int',
        'safety_stock'  => 'float',
        // V_1.1
        'reference_cost_price' => 'float',
        'reference_sale_price' => 'float',
    ];

    /** 关联计量单位 */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /** 关联商品分类 */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}