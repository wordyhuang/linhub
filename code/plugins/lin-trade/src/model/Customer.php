<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 客户
 */
class Customer extends BaseModel
{
    protected $table = 'lg_lin-trade_customer';

    protected $type = [
        'status' => 'int',
    ];
}