<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use linhub\host\migration\DbGateway;
use linhub\host\services\support\PlatformStore;

/**
 * RBAC 数据落库存储（S5 持久化 - DB 版）。
 *
 * 与 {@see RbacStorage} 暴露完全一致的分区读写/导出导入语义，但底层落在系统库 lin_hub，
 * 五张表（统一 lg_mgt_rbac_ 前缀）承载五个分区：
 *   - lg_mgt_rbac_role       ↔ roles        （roleId => 角色记录，payload JSON 存整体）
 *   - lg_mgt_rbac_group      ↔ groups       （groupId => 权限组，payload JSON 存整体）
 *   - lg_mgt_rbac_user       ↔ users        （userId => 用户，payload JSON 存整体；ref_id 冗余便于反查）
 *   - lg_mgt_rbac_user_role  ↔ user_roles   （userId, roleId 行式多对多，天然免整段替换）
 *   - lg_mgt_rbac_user_group ↔ user_groups  （userId, groupId 行式多对多）
 *
 * 设计约定与 PlatformStore 一致：裸 SQL + 手写方言匹配、幂等建表、
 * 字符串值手工 quote 转义；单分区写操作在事务内 DELETE+INSERT，天然避免
 * 文件版"读→改→写"三拍导致的并发覆盖风险。
 *
 * 语义差异（与文件版）：关联分区（user_roles/user_groups）按行式落库，
 * 空关系（如 userId => []）不产生任何行、回读也不保留该键；消费方一律以
 * "缺失键=空列表"处理（section()[uid] ?? [] / 空列表迭代），语义等价。
 *
 * /write_rules: 表名沿用迁移 SQL 风格；时间为 date('c') 字符串（本类无需时间列，payload 自含）。
 */
class DbRbacStorage extends RbacStorage
{
    /** 表名常量 */
    public const ROLE_TABLE       = 'lg_mgt_rbac_role';
    public const GROUP_TABLE      = 'lg_mgt_rbac_group';
    public const USER_TABLE       = 'lg_mgt_rbac_user';
    public const USER_ROLE_TABLE  = 'lg_mgt_rbac_user_role';
    public const USER_GROUP_TABLE = 'lg_mgt_rbac_user_group';

    private DbGateway $db;

    public function __construct(?DbGateway $db = null)
    {
        parent::__construct(); // 父类仅作基座，不触碰 JSON 文件
        $this->db = $db ?? PlatformStore::connect();
        $this->ensure();
    }

    /** 幂等建立五张 RBAC 表。 */
    public function ensure(): void
    {
        $this->ensureTable(self::ROLE_TABLE);
        $this->ensureTable(self::GROUP_TABLE);
        $this->ensureTable(self::USER_TABLE);
        $this->ensureTable(self::USER_ROLE_TABLE);
        $this->ensureTable(self::USER_GROUP_TABLE);
    }

    private function ensureRowsTable(string $table): void
    {
        if (in_array($table, $this->db->tables(), true)) {
            return;
        }
        // 多对多关联表：sid(用户) 可持有多个 rid(角色/权限组)，故仅 (sid,rid) 联合唯一，二者各自不唯一。
        $this->db->exec(
            'CREATE TABLE ' . $table . ' ('
            . $this->pkSql() . ', '
            . $this->qcol('sid') . ' VARCHAR(191) NOT NULL, '
            . $this->qcol('rid') . ' VARCHAR(191) NOT NULL, '
            . 'UNIQUE (' . $this->qcol('sid') . ', ' . $this->qcol('rid') . '))'
            . $this->tableOptions()
        );
        if ($this->db->dialect() === 'mysql') {
            $this->db->exec('ALTER TABLE ' . $table . ' ADD INDEX idx_sid (' . $this->qcol('sid') . ')');
        }
    }

    private function ensureTable(string $table): void
    {
        if (in_array($table, $this->db->tables(), true)) {
            return;
        }
        if ($table === self::USER_ROLE_TABLE || $table === self::USER_GROUP_TABLE) {
            $this->ensureRowsTable($table);
            return;
        }
        // 单实体分区表：业务键 + payload JSON
        $keyCol = match ($table) {
            self::ROLE_TABLE  => 'role_id',
            self::GROUP_TABLE => 'group_id',
            self::USER_TABLE  => 'user_id',
            default           => 'id',
        };
        $this->db->exec(
            'CREATE TABLE ' . $table . ' ('
            . $this->pkSql() . ', '
            . $this->qcol($keyCol) . ' VARCHAR(191) NOT NULL UNIQUE, '
            . ($table === self::USER_TABLE ? $this->qcol('ref_id') . " VARCHAR(191) NOT NULL DEFAULT '', " : '')
            . $this->qcol('payload') . ' TEXT)'
            . $this->tableOptions()
        );
        if ($this->db->dialect() === 'mysql') {
            $this->db->exec('ALTER TABLE ' . $table . ' ADD INDEX idx_' . $keyCol . ' (' . $this->qcol($keyCol) . ')');
        }
    }

    private function pkSql(): string
    {
        return match ($this->db->dialect()) {
            'mysql' => 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    private function tableOptions(): string
    {
        return $this->db->dialect() === 'mysql' ? ' ENGINE=InnoDB' : '';
    }

    private function qcol(string $c): string
    {
        return '`' . str_replace('`', '', $c) . '`';
    }

    /** SQL 字符串字面量转义（与 PlatformStore 同一约定）。 */
    private function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    protected function entityKeyCol(string $key): ?string
    {
        return match ($key) {
            self::ROLES => 'role_id',
            self::GROUPS => 'group_id',
            self::USERS => 'user_id',
            default => null,
        };
    }

    private function pairTable(string $key): ?string
    {
        return match ($key) {
            self::USER_ROLES => self::USER_ROLE_TABLE,
            self::USER_GROUPS => self::USER_GROUP_TABLE,
            default => null,
        };
    }

    public function section(string $key): array
    {
        $table = $this->pairTable($key);
        if ($table !== null) {
            // 多对多关系：sid(user_id) => [rid(roleId/groupId)...]
            $rows = $this->db->query('SELECT ' . $this->qcol('sid') . ', ' . $this->qcol('rid')
                . ' FROM ' . $table . ' ORDER BY ' . $this->qcol('id'));
            $grouped = [];
            foreach ($rows as $r) {
                $grouped[(string) $r['sid']][] = $r['rid'];
            }
            return $grouped;
        }
        $keyCol = $this->entityKeyCol($key);
        if ($keyCol === null) {
            return [];
        }
        $table = $this->entityTable($key);
        $rows = $this->db->query('SELECT ' . $this->qcol($keyCol) . ', ' . $this->qcol('payload')
            . ' FROM ' . $table . ' ORDER BY ' . $this->qcol('id'));
        $map = [];
        foreach ($rows as $r) {
            $decoded = json_decode((string) ($r['payload'] ?? '[]'), true);
            $map[(string) $r[$keyCol]] = is_array($decoded) ? $decoded : [];
        }
        return $map;
    }

    private function entityTable(string $key): string
    {
        return match ($key) {
            self::ROLES => self::ROLE_TABLE,
            self::GROUPS => self::GROUP_TABLE,
            self::USERS => self::USER_TABLE,
            default => '',
        };
    }

    public function putSection(string $key, array $value): void
    {
        $table = $this->pairTable($key);
        $this->db->begin();
        try {
            if ($table !== null) {
                $this->db->exec('DELETE FROM ' . $table);
                $sidCol = $this->qcol('sid');
                $ridCol = $this->qcol('rid');
                foreach ($value as $sid => $rids) {
                    foreach (array_values(array_unique(array_map('strval', (array) $rids))) as $rid) {
                        $this->db->exec('INSERT INTO ' . $table . ' (' . $sidCol . ', ' . $ridCol . ') VALUES ('
                            . $this->quote((string) $sid) . ', ' . $this->quote((string) $rid) . ')');
                    }
                }
            } else {
                $keyCol = $this->entityKeyCol($key);
                $table = $this->entityTable($key);
                $this->db->exec('DELETE FROM ' . $table);
                $keyColQ = $this->qcol((string) $keyCol);
                $payloadQ = $this->qcol('payload');
                $refQ = $this->qcol('ref_id');
                foreach ($value as $id => $record) {
                    $payload = $this->quote((string) json_encode($record, JSON_UNESCAPED_UNICODE));
                    $idQ = $this->quote((string) $id);
                    if ($table === self::USER_TABLE) {
                        $ref = $this->quote((string) (is_array($record) ? ($record['ref_id'] ?? '') : ''));
                        $this->db->exec('INSERT INTO ' . $table . ' (' . $keyColQ . ', ' . $refQ . ', ' . $payloadQ
                            . ') VALUES (' . $idQ . ', ' . $ref . ', ' . $payload . ')');
                    } else {
                        $this->db->exec('INSERT INTO ' . $table . ' (' . $keyColQ . ', ' . $payloadQ
                            . ') VALUES (' . $idQ . ', ' . $payload . ')');
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function find(string $key, string $id): ?array
    {
        $pairTable = $this->pairTable($key);
        if ($pairTable !== null) {
            return $this->section($key)[$id] ?? null;
        }
        $keyCol = $this->entityKeyCol($key);
        if ($keyCol === null) {
            return null;
        }
        $table = $this->entityTable($key);
        $rows = $this->db->query('SELECT ' . $this->qcol('payload') . ' FROM ' . $table
            . ' WHERE ' . $this->qcol((string) $keyCol) . ' = ' . $this->quote($id) . ' LIMIT 1');
        if (!$rows) {
            return null;
        }
        $decoded = json_decode((string) ($rows[0]['payload'] ?? '[]'), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function exportAll(): array
    {
        $data = [];
        foreach ([self::ROLES, self::GROUPS, self::USERS, self::USER_ROLES, self::USER_GROUPS] as $key) {
            $data[$key] = $this->section($key);
        }
        return $data;
    }

    public function importAll(array $data): void
    {
        $keep = [self::ROLES, self::GROUPS, self::USERS, self::USER_ROLES, self::USER_GROUPS];
        $this->db->begin();
        try {
            foreach ($keep as $key) {
                $this->putSection($key, is_array($data[$key] ?? null) ? $data[$key] : []);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}