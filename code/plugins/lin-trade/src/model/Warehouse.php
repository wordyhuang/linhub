<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 仓库
 */
class Warehouse extends BaseModel
{
    protected $table = 'lg_lin-trade_warehouse';

    protected $type = [
        'is_default' => 'int',
        'status'     => 'int',
    ];
}