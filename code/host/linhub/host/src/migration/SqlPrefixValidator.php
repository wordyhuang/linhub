<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 前缀隔离命名校验（T3.5 / P4 / R3 / L）
 *
 * 校验迁移脚本中 CREATE/ALTER 的目标对象（表/索引/视图）名称必须以 `lg_<short>_` 或 `lg_mgt_` 开头。
 * 其中 `lg_mgt_` 为平台级前缀，专项用于「平台相关服务与能力的落库表」（见 LinserviceStore / PlatformStore）。
 * 采用轻量 SQL 简配校验：识别 CREATE TABLE / ALTER TABLE / CREATE INDEX / CREATE VIEW 语句并提取目标对象名，
 * 对无法识别的语句做宽松放行（不过度做 SQL 解析），仅拦截可明确识别且前缀不合法的对象。
 */
final class SqlPrefixValidator
{
    /** 平台级放行前缀（不属于任何 `lg_<short>_` 包命名空间）。 */
    public const PLATFORM_PREFIXES = ['lg_mgt_'];

    /** @var list<string> 允许的前缀表 */
    private array $allowed;

    /** @var string 包短名（用于生成 lg_<short>_） */
    private string $short;

    public function __construct(string $short, array $managementPrefixes = self::PLATFORM_PREFIXES)
    {
        $this->short = $short;
        $this->allowed = array_merge(['lg_' . $short . '_'], array_values($managementPrefixes));
    }

    public function allowedPrefixes(): array
    {
        return $this->allowed;
    }

    /**
     * 校验一段（可能含多条语句的）SQL。
     *
     * @return list<string> 不合规对象描述列表；空表示通过
     */
    public function validate(string $sql): array
    {
        $problems = [];
        foreach ($this->splitStatements($sql) as $stmt) {
            $obj = $this->extractTarget($stmt);
            if ($obj === null) {
                continue; // 无法识别的语句，宽松放行
            }
            if (!$this->isAllowed($obj['name'])) {
                $problems[] = "{$obj['kind']} `{$obj['name']}` 前缀不合法，须以 " . implode(' / ', $this->allowed) . ' 开头';
            }
        }
        return $problems;
    }

    private function isAllowed(string $name): bool
    {
        $n = strtolower(trim($name, '`"[]'));
        foreach ($this->allowed as $p) {
            if (str_starts_with($n, strtolower($p))) {
                return true;
            }
        }
        return false;
    }

    /** 按分号拆分为语句列表（忽略末尾空串）。 */
    private function splitStatements(string $sql): array
    {
        $raw = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [$sql];
        $out = [];
        foreach ($raw as $s) {
            $s = trim((string) $s);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * 提取语句目标对象。
     *
     * @return array{kind:string,name:string}|null
     */
    private function extractTarget(string $stmt): ?array
    {
        // 说明：表/索引/视图名允许出现连字符（如包短名 lin-trade 生成前缀 lg_lin-trade_），
        // 故字符集用 [A-Za-z0-9_-]+ 而非 [A-Za-z0-9_]+（否则 hyphen 会把 lg_lin-trade_x 截成 lg_lin）。
        // CREATE TABLE [IF NOT EXISTS] 名称
        if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'table', 'name' => $m[2]];
        }
        // ALTER TABLE 名称
        if (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'table', 'name' => $m[1]];
        }
        // DROP TABLE
        if (preg_match('/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'table', 'name' => $m[2]];
        }
        // CREATE [UNIQUE] INDEX 名称 ON 表
        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'index', 'name' => $m[3]];
        }
        // DROP INDEX
        if (preg_match('/^DROP\s+INDEX\s+(IF\s+EXISTS\s+)?`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'index', 'name' => $m[2]];
        }
        // CREATE VIEW
        if (preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'view', 'name' => $m[1]];
        }
        if (preg_match('/^CREATE\s+VIEW\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_-]+)`?/i', $stmt, $m)) {
            return ['kind' => 'view', 'name' => $m[2]];
        }
        return null;
    }
}