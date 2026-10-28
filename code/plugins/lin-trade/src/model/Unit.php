<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 计量单位
 */
class Unit extends BaseModel
{
    protected $table = 'lg_lin-trade_unit';

    protected $type = [
        'status' => 'int',
    ];
}