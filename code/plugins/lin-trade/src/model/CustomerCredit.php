<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 客户信用
 */
class CustomerCredit extends BaseModel
{
    protected $table = 'lg_lin-trade_customer_credit';

    protected $type = [
        'customer_id'  => 'int',
        'credit_limit' => 'float',
        'used_amount'  => 'float',
    ];

    /** 客户 */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}