<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 客户信用操作留痕（PRD 3.9.1 额度调整留改前/改后；3.9.2/3.9.3 结清来源留痕）
 */
class CreditLog extends BaseModel
{
    protected $table = 'lg_lin-trade_credit_log';

    protected $type = [
        'customer_id'   => 'int',
        'before_amount' => 'float',
        'after_amount'  => 'float',
        'operator'      => 'int',
        'ref_id'        => 'int',
    ];

    /** 客户 */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}