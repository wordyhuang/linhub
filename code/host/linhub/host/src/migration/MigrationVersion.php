<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 迁移版本排序工具（T3.1/R1）
 *
 * 提供语义化版本的 compare 排序，用于"版本 → 序号"的有序执行。
 */
final class MigrationVersion
{
    private function __construct()
    {
    }

    /** 规范化版本为 [主,次,修订] 数字元组（不符规则按 0 处理）。 */
    private static function parts(string $version): array
    {
        $m = [];
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', trim($version), $m);
        return [
            (int) ($m[1] ?? 0),
            (int) ($m[2] ?? 0),
            (int) ($m[3] ?? 0),
        ];
    }

    /**
     * 版本比较：$a < $b 返回负，== 返回 0，> 返回正。
     */
    public static function compare(string $a, string $b): int
    {
        return self::parts($a) <=> self::parts($b);
    }
}