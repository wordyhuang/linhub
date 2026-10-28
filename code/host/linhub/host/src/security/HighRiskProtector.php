<?php

declare(strict_types=1);

namespace linhub\host\security;

/**
 * 高危操作保护（T7.2 / R4 / G8）
 *
 * 识别并对高危操作施加保护：SQL 中的 DROP / TRUNCATE / 无 WHERE 的 DELETE（清空表）
 * 以及批量删除超阈值。默认策略：拦截并抛 SecurityException；
 * 亦支持以显式确认 token 放行（allowWithConfirm，token 匹配方返回 true）。
 */
final class HighRiskProtector
{
    /** 高危 SQL 关键词（词边界）。 */
    private const HIGH_RISK_SQL = ['DROP', 'TRUNCATE'];

    /** 显式确认 token（可空，null 时确认永久不可用）。 */
    private ?string $confirmToken;

    public function __construct(?string $confirmToken = null)
    {
        $this->confirmToken = $confirmToken;
    }

    /** 判断一段 SQL 是否属于高危（DROP/TRUNCATE/无 WHERE 的 DELETE）。 */
    public function isRiskySql(string $sql): bool
    {
        $upper = strtoupper(trim($sql));
        foreach (self::HIGH_RISK_SQL as $kw) {
            if (str_contains(" $upper ", " $kw ")) {
                return true;
            }
        }
        // 无 WHERE 的空表 DELETE 视为清表高危
        if (preg_match('/\bDELETE\s+FROM\b/i', $sql) && !preg_match('/\bWHERE\b/i', $sql)) {
            return true;
        }
        return false;
    }

    /** 断言 SQL 安全：命中高危关键词则抛 SecurityException。 */
    public function assertSqlSafe(string $sql): void
    {
        if ($this->isRiskySql($sql)) {
            throw new SecurityException("高危 SQL 被拦截：{$sql}");
        }
    }

    /** 断言批量删除行数在阈值内：超阈值则抛 SecurityException。 */
    public function assertBulkDeleteCount(int $affectedRows, int $threshold): void
    {
        if ($affectedRows > $threshold) {
            throw new SecurityException("批量删除超过阈值 {$threshold}：影响 {$affectedRows} 行");
        }
    }

    /**
     * 显式确认：token 与构造时注入的确认 token 匹配方返回 true，否则抛 SecurityException。
     * 调用方据此可放行已确认的高危操作。
     */
    public function allowWithConfirm(string $token): bool
    {
        if ($this->confirmToken === null || $token === '' || !hash_equals($this->confirmToken, $token)) {
            throw new SecurityException('高危操作显式确认失败：确认 token 无效');
        }
        return true;
    }
}