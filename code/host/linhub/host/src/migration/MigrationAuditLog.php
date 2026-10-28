<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 迁移审计（T3.6 / R5 / B4）
 *
 * 每次迁移/回退写入审计记录（执行人、时间、SQL 摘要），支持按包/版本/方向检索。
 * 审计表默认 `lg_mgt_migration_log`，与版本记录表（lg_mgt_migration）分离，
 * 保证"回滚过的历史"也能被追溯，而不影响当前已执行集合的判定。
 */
final class MigrationAuditLog
{
    /** @var DbGateway */
    private DbGateway $db;

    /** @var string */
    private string $table;

    public function __construct(DbGateway $db, string $table = 'lg_mgt_migration_log')
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** 确保审计表存在（幂等）。 */
    public function ensure(): void
    {
        if (in_array($this->table, $this->db->tables(), true)) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE {$this->table} ("
            . $this->primaryKeySql() . ', '
            . 'package TEXT NOT NULL, '
            . 'version TEXT NOT NULL, '
            . 'migration TEXT NOT NULL, '
            . 'direction TEXT NOT NULL, '
            . 'snapshot TEXT NOT NULL, '
            . 'sql_summary TEXT DEFAULT \'\', '
            . 'operator TEXT DEFAULT \'\', '
            . 'roles TEXT DEFAULT \'\', '
            . 'executed_at TEXT DEFAULT \'\')'
            . $this->tableOptions()
        );
    }

    /** 按方言生成自增主键定义（sqlite / mysql / pgsql）。 */
    private function primaryKeySql(): string
    {
        return match ($this->db->dialect()) {
            'mysql' => 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    /** 表级选项；MySQL 默认引擎可能为 MyISAM（不支持事务），平台自建表强制 InnoDB 以保障事务化迁移（T3.3/R4）。 */
    private function tableOptions(): string
    {
        return $this->db->dialect() === 'mysql' ? ' ENGINE=InnoDB' : '';
    }

    /**
     * 写入一条审计记录。
     *
     * @param array<string,mixed> $row package/version/migration/direction/snapshot/sql_summary/operator/roles/executed_at
     */
    public function write(array $row): void
    {
        $this->ensure();
        $this->db->exec(
            "INSERT INTO {$this->table} (package, version, migration, direction, snapshot, sql_summary, operator, roles, executed_at) VALUES ("
            . $this->quote((string) ($row['package'] ?? '')) . ', '
            . $this->quote((string) ($row['version'] ?? '')) . ', '
            . $this->quote((string) ($row['migration'] ?? '')) . ', '
            . $this->quote((string) ($row['direction'] ?? '')) . ', '
            . $this->quote((string) ($row['snapshot'] ?? '')) . ', '
            . $this->quote((string) ($row['sql_summary'] ?? '')) . ', '
            . $this->quote((string) ($row['operator'] ?? '')) . ', '
            . $this->quote((string) ($row['roles'] ?? '')) . ', '
            . $this->quote((string) ($row['executed_at'] ?? date('c'))) . ')'
        );
    }

    /**
     * 检索审计记录。
     *
     * @param array<string,mixed> $filter package/version/direction/operator 任意组合
     * @return list<array<string,mixed>>
     */
    public function search(array $filter = []): array
    {
        $this->ensure();
        $where = [];
        foreach (['package', 'version', 'direction', 'operator', 'migration'] as $key) {
            if (isset($filter[$key]) && $filter[$key] !== '' && $filter[$key] !== null) {
                $where[] = "{$key}=" . $this->quote((string) $filter[$key]);
            }
        }
        $sql = 'SELECT * FROM ' . $this->table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id';
        return $this->db->query($sql);
    }

    private function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}