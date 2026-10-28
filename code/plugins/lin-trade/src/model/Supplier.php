<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 供应商
 */
class Supplier extends BaseModel
{
    protected $table = 'lg_lin-trade_supplier';

    protected $type = [
        'status' => 'int',
    ];
}