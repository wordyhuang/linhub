<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

/**
 * 销售订单
 */
class SaleOrder extends BaseModel
{
    protected $table = 'lg_lin-trade_sale_order';

    protected $type = [
        'customer_id'  => 'int',
        'warehouse_id' => 'int',
        'total_amount' => 'float',
        'outbound_no'  => 'int',
        'created_by'   => 'int',
        // V_1.1 折扣
        'discount_rate'   => 'float',
        'discount_amount' => 'float',
    ];

    /** 明细行 */
    public function items()
    {
        return $this->hasMany(SaleOrderItem::class, 'order_id');
    }

    /** 客户 */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}