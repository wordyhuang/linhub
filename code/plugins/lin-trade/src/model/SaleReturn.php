<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 销售退货单
 */
class SaleReturn extends BaseModel
{
    protected $table = 'lg_lin-trade_sale_return';

    protected $type = [
        'sale_order_id' => 'int',
        'customer_id'   => 'int',
        'require_check' => 'int',
        'refund_flag'   => 'int',
        'total_amount'  => 'float',
        'created_by'    => 'int',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(SaleReturnItem::class, 'return_id');
    }

    /** 客户 */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}