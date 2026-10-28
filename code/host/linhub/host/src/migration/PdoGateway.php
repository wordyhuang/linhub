<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 基于真实 PDO 的网关实现（T3.1/R1）。
 *
 * 事务通过 PDO->beginTransaction/commit/rollback 管理；开启异常模式使 SQL 失败立即抛异常（供迁移回滚）。
 * 结构访问按方言（sqlite / mysql / pgsql）适配；不支持的方言返回空结构并给出告警，不阻断业务主流程。
 */
final class PdoGateway implements DbGateway
{
    /** @var \PDO */
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
    }

    private function driver(): string
    {
        return strtolower($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function dialect(): string
    {
        return $this->driver();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function query(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function tables(): array
    {
        $drv = $this->driver();
        $rows = match ($drv) {
            'sqlite' => $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            'mysql'  => $this->query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"),
            'pgsql'  => $this->query("SELECT tablename AS name FROM pg_tables WHERE schemaname='public'"),
            default  => [],
        };
        return array_values(array_unique(array_map(fn ($r) => strtolower((string) ($r['name'] ?? '')), $rows)));
    }

    public function columns(string $table): array
    {
        $drv = $this->driver();
        $rows = match ($drv) {
            'sqlite' => $this->query("PRAGMA table_info(`{$table}`)"),
            'mysql'  => $this->query(
                "SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS k, COLUMN_DEFAULT AS d "
                . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->pdo->quote($table)
            ),
            'pgsql'  => $this->query(
                "SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS d "
                . "FROM information_schema.columns WHERE table_name=" . $this->pdo->quote($table)
            ),
            default  => [],
        };
        $out = [];
        foreach ($rows as $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'    => $name,
                'type'    => strtolower((string) ($r['type'] ?? '')),
                'nullable' => $this->isNullable($drv, $r),
                'primary' => $this->isPrimary($drv, $r),
                'default' => $r['d'] ?? $r['default'] ?? null,
            ];
        }
        return $out;
    }

    private function isNullable(string $drv, array $r): bool
    {
        if ($drv === 'sqlite') {
            return (int) ($r['notnull'] ?? 1) === 0;
        }
        $nv = strtolower((string) ($r['nullable'] ?? ''));
        return $drv === 'pgsql' ? $nv === 'yes' : $nv === 'yes';
    }

    private function isPrimary(string $drv, array $r): bool
    {
        if ($drv === 'sqlite') {
            return (int) ($r['pk'] ?? 0) === 1;
        }
        return strtoupper((string) ($r['k'] ?? '')) === 'PRI';
    }

    public function indexes(string $table): array
    {
        $drv = $this->driver();
        $rows = match ($drv) {
            'sqlite' => $this->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=" . $this->pdo->quote($table)),
            'mysql'  => $this->query(
                "SELECT INDEX_NAME AS name, NON_UNIQUE AS nu, COLUMN_NAME AS col FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->pdo->quote($table) . " ORDER BY SEQ_IN_INDEX"
            ),
            'pgsql'  => $this->query(
                "SELECT indexname AS name, indexdef AS def FROM pg_indexes WHERE tablename=" . $this->pdo->quote($table)
            ),
            default  => [],
        };
        $grouped = [];
        foreach ($rows as $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($drv === 'pgsql') {
                $def = (string) ($r['def'] ?? '');
                $unique = stripos($def, 'CREATE UNIQUE') === 0;
            } else {
                $unique = ($r['unique'] ?? '') === 1 || ($r['nu'] ?? 1) == 0;
            }
            if (!isset($grouped[$name])) {
                $grouped[$name] = ['name' => $name, 'columns' => [], 'unique' => $unique];
            }
            $grouped[$name]['columns'][] = (string) ($r['col'] ?? '');
        }
        return array_values($grouped);
    }

    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function summarize(string $sql): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        if ($t === '') {
            return '';
        }
        $first = stripos($t, ' ');
        $kw = strtoupper(substr($t, 0, $first === false ? strlen($t) : $first));
        $obj = $this->targetObject($t, $kw);
        return $obj === '' ? $kw : $kw . ' ' . $obj;
    }

    /** 从 SQL 首句识别其作用对象名（表/索引），用于审计摘要。 */
    private function targetObject(string $sql, string $kw): string
    {
        if (preg_match('/\b(' . $kw . ')\s+(?:TABLE|INDEX|VIEW)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            return (string) $m[2];
        }
        if (preg_match('/\b(TABLE|INDEX|VIEW)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            return (string) $m[2];
        }
        return '';
    }
}