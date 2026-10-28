<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 统一迁移执行引擎（T3.1/T3.2/T3.3/R1/P3/R4）
 *
 * 职责：
 * - 按"版本 → 序号"顺序执行功能包 migrations/<版本>/<序号>_<描述>.up.sql 脚本链；
 * - up/down 成对脚本（T3.2），提供 rollback() 执行 down 撤销结构变化；
 * - 事务化迁移（T3.3/R4）：每一条迁移 SQL 在数据库事务内执行，失败自动回滚该迁移，不留半状态；
 * - 每次执行写入版本记录（T3.1）与审计（T3.6）。
 */
final class MigrationRunner
{
    /** @var DbGateway */
    private DbGateway $db;

    /** @var MigrationTable */
    private MigrationTable $table;

    /** @var MigrationAuditLog */
    private MigrationAuditLog $audit;

    /** @var string */
    private string $operator;

    /** @var string */
    private string $roles;

    public function __construct(
        DbGateway $db,
        ?MigrationTable $table = null,
        ?MigrationAuditLog $audit = null,
        string $operator = 'system',
        string $roles = '',
    ) {
        $this->db = $db;
        $this->table = $table ?? new MigrationTable($db);
        $this->audit = $audit ?? new MigrationAuditLog($db);
        $this->operator = $operator;
        $this->roles = $roles;
    }

    /** 迁移目录是否可用（存在 migrations 目录）。 */
    public static function migrationsDir(string $migrationsRoot, string $short, string $sub = 'migrations'): string
    {
        return rtrim($migrationsRoot, '/\\') . '/' . $short . '/' . trim($sub, '/');
    }

    /**
     * 发现某版本目录下的迁移脚本（按序号升序）。
     *
     * @return list<array{seq:string,name:string,version:string,up:string,down:string}>
     */
    public function discoverVersion(string $migrationsRoot, string $short, string $version, string $sub = 'migrations'): array
    {
        $dir = self::migrationsDir($migrationsRoot, $short, $sub) . '/' . $version;
        if (!is_dir($dir)) {
            return [];
        }
        $scripts = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match('/^(\d+)_(.+?)\.up\.sql$/i', $file, $m)) {
                continue;
            }
            $seq = str_pad($m[1], 4, '0', STR_PAD_LEFT);
            $stem = $m[1] . '_' . $m[2];
            $down = $dir . '/' . $m[1] . '_' . $m[2] . '.down.sql';
            $scripts[] = [
                'seq'     => $seq,
                'name'    => $stem,
                'key'     => $version . '/' . $stem,
                'version' => $version,
                'up'      => $dir . '/' . $file,
                'down'    => is_file($down) ? $down : '',
            ];
        }
        usort($scripts, fn ($a, $b) => strcmp($a['seq'], $b['seq']));
        return $scripts;
    }

    /**
     * 发现已落地的全部版本目录（语义化升序）。
     *
     * @return list<string>
     */
    public static function discoverVersions(string $migrationsRoot, string $short, string $sub = 'migrations'): array
    {
        $base = self::migrationsDir($migrationsRoot, $short, $sub);
        if (!is_dir($base)) {
            return [];
        }
        $versions = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) {
                continue;
            }
            if (preg_match('/^\d+\.\d+\.\d+$/', $entry)) {
                $versions[] = $entry;
            }
        }
        usort($versions, [MigrationVersion::class, 'compare']);
        return $versions;
    }

    /**
     * 安装：把一个包升级/回退/首装到指定版本，执行该版本内所有尚未执行的 up 脚本，按序号升序。
     *
     * @param string $package 包名（lgg/member 形式）
     * @param string $short 包短名
     * @param string $targetVersion 目标版本（安装=manifest.version）
     * @return list<string> 实际执行的迁移标识（package/version/name）
     */
    public function upgrade(array $manifest, string $migrationsRoot, string $sub = 'migrations'): array
    {
        $package = (string) ($manifest['name'] ?? '');
        $short = (string) ($manifest['short'] ?? '');
        $target = (string) ($manifest['version'] ?? '');
        $this->table->ensure();
        $this->audit->ensure();
        $applied = $this->table->appliedSet($package);
        $executed = [];

        // 收集 <= target 版本的全部脚本，按 版本→序号 排序
        $all = [];
        foreach (self::discoverVersions($migrationsRoot, $short, $sub) as $version) {
            if (MigrationVersion::compare($version, $target) > 0) {
                continue;
            }
            foreach ($this->discoverVersion($migrationsRoot, $short, $version, $sub) as $scr) {
                $key = $version . '/' . $scr['name'];
                $scr['key'] = $key;
                $all[] = $scr;
            }
        }

        foreach ($all as $scr) {
            if (isset($applied[$scr['key']])) {
                continue; // 已执行过，跳过（幂等）
            }
            $this->executeScript($package, $short, $scr, 'up');
            $executed[] = $scr['key'];
        }
        return $executed;
    }

    /** 便捷 API：直接升级到指定版本。 */
    public function upToVersion(string $package, string $short, string $toVersion, string $migrationsRoot, string $sub = 'migrations'): array
    {
        return $this->upgrade(
            ['name' => $package, 'short' => $short, 'version' => $toVersion],
            $migrationsRoot,
            $sub,
        );
    }

    /**
     * 回退：执行某版本目录内所有 down 脚本（按序号倒序），撤销该版本的 up 结构变化。
     *
     * @param array<string,mixed> $manifest
     * @return list<string> 实际回退的迁移标识
     */
    public function rollback(array $manifest, string $migrationsRoot, string $fromVersion, string $sub = 'migrations'): array
    {
        $package = (string) ($manifest['name'] ?? '');
        $short = (string) ($manifest['short'] ?? '');
        $this->table->ensure();
        $this->audit->ensure();
        $scripts = $this->discoverVersion($migrationsRoot, $short, $fromVersion, $sub);
        // 倒序
        $scripts = array_reverse($scripts);
        $executed = [];
        foreach ($scripts as $scr) {
            if ($scr['down'] === '') {
                continue; // 无 down 脚本则跳过
            }
            $this->executeScript($package, $short, $scr, 'down');
            $executed[] = $fromVersion . '/' . $scr['name'];
        }
        return $executed;
    }

    /**
     * 执行单个脚本（up 或 down），整条脚本包裹在一个事务内，失败自动回滚。
     */
    private function executeScript(string $package, string $short, array $scr, string $direction): void
    {
        $prefix = new SqlPrefixValidator($short);
        $preSnapshot = $this->schemaSnapshotJson();
        $this->db->begin();
        try {
            $sql = file_get_contents($scr['up']);
            if ($direction === 'down') {
                $sql = file_get_contents($scr['down']);
            }
            if ($sql === false) {
                throw new \RuntimeException("迁移脚本读取失败：{$scr['up']}");
            }

            // 前缀校验
            $problems = $prefix->validate($sql);
            if ($problems !== []) {
                throw new \RuntimeException('迁移脚本前缀校验失败：' . implode('; ', $problems) . "（{$scr['key']}）");
            }

            // 逐条执行（DML/DDL）
            foreach ($this->splitStatements($sql) as $stmt) {
                $this->db->exec($stmt);
            }

            // 版本记录 + 审计
            if ($direction === 'up') {
                $this->table->record([
                    'package' => $package, 'version' => $scr['version'],
                    'migration' => $scr['name'], 'direction' => 'up',
                    'roles' => $this->roles, 'executed_at' => date('c'),
                ]);
            }
            $this->audit->write([
                'package' => $package, 'version' => $scr['version'],
                'migration' => $scr['name'], 'direction' => $direction,
                'snapshot' => $preSnapshot, 'sql_summary' => $this->db->summarize($sql),
                'operator' => $this->operator, 'roles' => $this->roles, 'executed_at' => date('c'),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            // 失败自动回滚该迁移，不留半状态
            $this->db->rollback();
            throw new \RuntimeException(
                "迁移失败已回滚 [{$scr['key']} @ {$direction}]：" . $e->getMessage(),
                0,
                $e,
            );
        }
    }

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

    private function schemaSnapshotJson(): string
    {
        try {
            return json_encode(SchemaSnapshot::fromGateway($this->db), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return '';
        }
    }
}