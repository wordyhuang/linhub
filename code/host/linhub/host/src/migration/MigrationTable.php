<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 迁移版本记录表（T3.1/R1 的一部分）
 *
 * 在目标库维护/读写一条迁移记录表（默认 `lg_mgt_migration`），
 * 记录已执行迁移（package、version、migration、executed_at、roles 等），用于幂等与升级判断。
 * 建表本身采用 DB 无关语法，可在 sqlite / mysql / pgsql 上运行。
 */
final class MigrationTable
{
    /** @var DbGateway */
    private DbGateway $db;

    /** @var string 迁移版本表名 */
    private string $table;

    public function __construct(DbGateway $db, string $table = 'lg_mgt_migration')
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** 确保版本表存在（幂等）。 */
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
     * 记录一次迁移执行（up 或 down）。
     *
     * @param array<string,mixed> $row package/version/migration/direction/roles
     */
    public function record(array $row): void
    {
        $this->ensure();
        $this->db->exec(
            "INSERT INTO {$this->table} (package, version, migration, direction, roles, executed_at) VALUES ("
            . $this->quote((string) ($row['package'] ?? '')) . ', '
            . $this->quote((string) ($row['version'] ?? '')) . ', '
            . $this->quote((string) ($row['migration'] ?? '')) . ', '
            . $this->quote((string) ($row['direction'] ?? 'up')) . ', '
            . $this->quote((string) ($row['roles'] ?? '')) . ', '
            . $this->quote((string) ($row['executed_at'] ?? date('c'))) . ')'
        );
    }

    /** 某迁移是否已以 up 方向执行过。 */
    public function isApplied(string $package, string $version, string $migration): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM {$this->table} WHERE package=" . $this->quote($package)
            . ' AND version=' . $this->quote($version)
            . ' AND migration=' . $this->quote($migration)
            . " AND direction='up'"
        );
        return $rows !== [];
    }

    /** 已对该包以 up 方向执行的迁移标识集合。 */
    public function appliedSet(string $package): array
    {
        $rows = $this->db->query(
            "SELECT version, migration FROM {$this->table} WHERE package=" . $this->quote($package) . " AND direction='up'"
        );
        $set = [];
        foreach ($rows as $r) {
            $set[(string) ($r['version'] ?? '') . '/' . (string) ($r['migration'] ?? '')] = true;
        }
        return $set;
    }

    /** 该包已 up 的版本列表（去重升序）。 */
    public function appliedVersions(string $package): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT version FROM {$this->table} WHERE package=" . $this->quote($package) . " AND direction='up'"
        );
        $vs = array_map(fn ($r) => (string) ($r['version'] ?? ''), $rows);
        usort($vs, [MigrationVersion::class, 'compare']);
        return $vs;
    }

    private function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}