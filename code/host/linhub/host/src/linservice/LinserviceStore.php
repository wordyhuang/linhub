<?php

declare(strict_types=1);

namespace linhub\host\linservice;

use linhub\host\migration\DbGateway;

/**
 * 平台服务与能力落库（lg_mgt_ 前缀，系统库 lin_hub）
 *
 * 将平台相关服务与能力的登记结果持久化到系统库，统一以 `lg_mgt_` 为前缀，
 * 作为内存注册表 / JSON 注册文件的持久化镜像：重启不丢、可离线查询：
 *  - lg_mgt_service    ：宿主服务注册表镜像（service_id / class / version / source）
 *  - lg_mgt_capability ：接入点能力镜像（pkg_key / cap_type / payload JSON）
 *
 * JSON/内存仍是能力与服务的唯一事实来源，本类仅做幂等同步（ensureSeeded 时全量镜像重建），
 * 不做任何启动语义或路由决策依赖。建表采用 DB 无关语法（与迁移引擎 MigrationTable 一致），
 * MySQL 强制 InnoDB 以保障事务一致性。
 */
final class LinserviceStore
{
    public const SERVICE_TABLE = 'lg_mgt_service';
    public const CAPABILITY_TABLE = 'lg_mgt_capability';

    /** @var DbGateway */
    private DbGateway $db;

    public function __construct(DbGateway $db)
    {
        $this->db = $db;
    }

    public function serviceTable(): string
    {
        return self::SERVICE_TABLE;
    }

    public function capabilityTable(): string
    {
        return self::CAPABILITY_TABLE;
    }

    /** 幂等建表（CREATE TABLE IF NOT EXISTS 风格；表已存在则跳过）。 */
    public function ensure(): void
    {
        if (!in_array(self::SERVICE_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::SERVICE_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'service_id VARCHAR(191) NOT NULL, '
                . 'class TEXT NOT NULL, '
                . 'version TEXT NOT NULL, '
                . 'source TEXT NOT NULL, '
                . "updated_at TEXT DEFAULT '')"
                . $this->tableOptions()
            );
        }
        if (!in_array(self::CAPABILITY_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::CAPABILITY_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'pkg_key VARCHAR(191) NOT NULL, '
                . 'cap_type VARCHAR(191) NOT NULL, '
                . "payload TEXT DEFAULT '', "
                . "updated_at TEXT DEFAULT '')"
                . $this->tableOptions()
            );
        }
    }

    /**
     * 全量镜像服务注册表（先清后插，保证任意次调用结果唯一且等于入参）。
     *
     * @param array<string, array{class?:string,version?:string,source?:string}> $registry 服务注册表
     * @return int 写入条数
     */
    public function persistServices(array $registry): int
    {
        $this->ensure();
        $t   = self::SERVICE_TABLE;
        $now = date('c');
        $this->db->exec("DELETE FROM {$t}");
        $n = 0;
        foreach ($registry as $id => $entry) {
            $this->db->exec(
                "INSERT INTO {$t} (service_id, class, version, source, updated_at) VALUES ("
                . $this->quote((string) $id) . ', '
                . $this->quote((string) ($entry['class'] ?? '')) . ', '
                . $this->quote((string) ($entry['version'] ?? '')) . ', '
                . $this->quote((string) ($entry['source'] ?? '')) . ', '
                . $this->quote($now) . ')'
            );
            $n++;
        }
        return $n;
    }

    /**
     * 全量镜像接入点能力（先清后插）。入参为 IntegrationRegistry::read() 扁平结果：
     * pkg_key => sections（permissions/menus/workspace/ui_replace...），逐项落一行 payload JSON。
     *
     * @param array<string, array<string, mixed>> $registry 按包分区的接入点登记
     * @return int 写入条数
     */
    public function persistCapabilities(array $registry): int
    {
        $this->ensure();
        $t   = self::CAPABILITY_TABLE;
        $now = date('c');
        $this->db->exec("DELETE FROM {$t}");
        $n = 0;
        foreach ($registry as $pkg => $sections) {
            if (!is_array($sections)) {
                continue;
            }
            foreach ($sections as $type => $payload) {
                if (!is_string($type)) {
                    continue;
                }
                $this->db->exec(
                    "INSERT INTO {$t} (pkg_key, cap_type, payload, updated_at) VALUES ("
                    . $this->quote((string) $pkg) . ', '
                    . $this->quote($type) . ', '
                    . $this->quote((string) json_encode($payload, JSON_UNESCAPED_UNICODE)) . ', '
                    . $this->quote($now) . ')'
                );
                $n++;
            }
        }
        return $n;
    }

    /**
     * 从库读取服务镜像（id => [class, version, source]）。
     *
     * @return array<string, array{class:string,version:string,source:string}>
     */
    public function servicesAll(): array
    {
        $rows = $this->db->query(
            'SELECT service_id, class, version, source FROM ' . self::SERVICE_TABLE . ' ORDER BY service_id'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) ($r['service_id'] ?? '')] = [
                'class'   => (string) ($r['class'] ?? ''),
                'version' => (string) ($r['version'] ?? ''),
                'source'  => (string) ($r['source'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 从库读取能力镜像（pkg_key => [cap_type => payload 数组]）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function capabilitiesAll(): array
    {
        $rows = $this->db->query(
            'SELECT pkg_key, cap_type, payload FROM ' . self::CAPABILITY_TABLE . ' ORDER BY pkg_key, cap_type'
        );
        $out = [];
        foreach ($rows as $r) {
            $pkg  = (string) ($r['pkg_key'] ?? '');
            $type = (string) ($r['cap_type'] ?? '');
            $out[$pkg][$type] = json_decode((string) ($r['payload'] ?? ''), true) ?? [];
        }
        return $out;
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

    /** 表级选项；MySQL 强制 InnoDB（与迁移引擎一致，保障事务一致性）。 */
    private function tableOptions(): string
    {
        return $this->db->dialect() === 'mysql' ? ' ENGINE=InnoDB' : '';
    }

    private function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}