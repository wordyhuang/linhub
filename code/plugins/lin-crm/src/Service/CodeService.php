<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Service;

use think\facade\Db;

/**
 * 编码服务：KH / XS / SJ / HT 四类单据编码（GR-1：前缀+yyyyMMdd+4位流水，只读不可改）
 */
class CodeService
{
    private const PREFIX_MAP = [
        'customer'    => 'KH',
        'lead'        => 'XS',
        'opportunity' => 'SJ',
        'contract'    => 'HT',
    ];

    public static function next(string $biz): string
    {
        if (!isset(self::PREFIX_MAP[$biz])) {
            throw new \InvalidArgumentException('unknown biz: ' . $biz); // 定义缺失 → 最保守拒绝
        }
        $table = 'lg_lin-crm_' . $biz;
        $prefix = self::PREFIX_MAP[$biz] . date('Ymd');
        $seq = (int) Db::name($table)->whereLike('code', $prefix . '%')->count();
        return $prefix . sprintf('%04d', $seq + 1);
    }
}
