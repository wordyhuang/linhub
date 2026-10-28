<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 商品分类
 */
class Category extends BaseModel
{
    protected $table = 'lg_lin-trade_category';

    protected $type = [
        'parent_id' => 'int',
        'sort'      => 'int',
        'status'    => 'int',
    ];
}