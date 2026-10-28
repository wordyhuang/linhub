<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 对账单
 */
class Reconciliation extends BaseModel
{
    protected $table = 'lg_lin-trade_reconciliation';

    protected $type = [
        'target_id'      => 'int',
        'balance_amount' => 'float',
        'created_by'     => 'int',
    ];

    /** 客户 */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'target_id');
    }

    /** 供应商 */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'target_id');
    }
}