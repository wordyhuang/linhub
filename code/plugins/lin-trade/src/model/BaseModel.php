<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\model;

use think\Model;

/**
 * 林销（进销存）功能包模型基类。
 *
 * 统一约定：所有业务表以 lg_lin-trade_ 为前缀（见 manifest.php comments），
 * 本基类关闭软删除、统一时间戳字段与 JSON 类型转换。
 */
abstract class BaseModel extends Model
{
    /**
     * 启用 ThinkPHP 自动时间戳并映射到 created_at/updated_at。
     * DB 侧不依赖 DEFAULT CURRENT_TIMESTAMP（MySQL 5.5 的 DATETIME 不支持该默认值），
     * 时间由应用层写入，兼容 5.5 及更高版本。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 字段类型转换：金额与数量统一为浮点，避免 DECIMAL 返回字符串 */
    protected $type = [
        'total_amount' => 'float',
        'quantity'     => 'float',
        'received_qty' => 'float',
        'returned_qty' => 'float',
        'outbound_qty' => 'float',
        'price'        => 'float',
        'amount'       => 'float',
        'safety_stock' => 'float',
        'book_qty'     => 'float',
        'actual_qty'   => 'float',
        'diff_qty'     => 'float',
        'before_qty'   => 'float',
        'change_qty'   => 'float',
        'after_qty'    => 'float',
        // V_1.1 新增字段：参考价/最低售价/信用/加权成本/折扣
        'reference_cost_price' => 'float',
        'reference_sale_price' => 'float',
        'level_price'          => 'float',
        'min_price'            => 'float',
        'credit_limit'         => 'float',
        'used_amount'          => 'float',
        'weighted_cost'        => 'float',
        'balance_amount'       => 'float',
        'discount_rate'        => 'float',
        'discount_amount'      => 'float',
    ];
}