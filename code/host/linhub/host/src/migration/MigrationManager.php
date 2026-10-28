<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 迁移管理器工厂（对接 S2 PackageInstaller 的 migrate 槽位）
 *
 * 提供可直接注入 `PackageInstaller(loadRoot, db, migrate: $handler, ...)` 的闭包工厂：
 * - installHandler(): 安装时执行 up（源码已落地 loadRoot/short、状态迁移之前调用）；
 * - upgradeHandler(): 升级到新版本执行 up；
 * - rollbackHandler(): 回退执行指定版本的下线脚本；
 * 返回闭包签名统一为 (array $manifest): array|void。
 */
final class MigrationManager
{
    /** @var DbGateway */
    private DbGateway $db;

    /** @var string */
    private string $loadRoot;

    /** @var string */
    private string $operator;

    /** @var string */
    private string $roles;

    /** @var MigrationRunner|null 惰性构建 */
    private ?MigrationRunner $runner = null;

    /** @var MigrationTable|null */
    private ?MigrationTable $table = null;

    /** @var MigrationAuditLog|null */
    private ?MigrationAuditLog $audit = null;

    public function __construct(
        string $loadRoot,
        DbGateway $db,
        string $operator = 'system',
        string $roles = '',
    ) {
        $this->loadRoot = rtrim($loadRoot, '/\\');
        $this->db = $db;
        $this->operator = $operator;
        $this->roles = $roles;
    }

    /** 以 real PDO 构建（等价于 new MigrationManager(loadRoot, new PdoGateway($pdo), ...)）。 */
    public static function withPdo(string $loadRoot, \PDO $pdo, string $operator = 'system', string $roles = ''): self
    {
        return new self($loadRoot, new PdoGateway($pdo), $operator, $roles);
    }

    private function runner(): MigrationRunner
    {
        if ($this->runner === null) {
            $this->table = new MigrationTable($this->db);
            $this->audit = new MigrationAuditLog($this->db);
            $this->runner = new MigrationRunner($this->db, $this->table, $this->audit, $this->operator, $this->roles);
        }
        return $this->runner;
    }

    public function table(): MigrationTable
    {
        $this->runner();
        return $this->table;
    }

    public function audit(): MigrationAuditLog
    {
        $this->runner();
        return $this->audit;
    }

    /**
     * 安装用 migrate 槽位。签名 (array $manifest): void，由安装器在提交状态前调用。
     *
     * 从 $loadRoot/$short/migrations 定位脚本并执行 up（幂等：已执行的跳过）。
     */
    public function installHandler(): callable
    {
        return function (array $manifest): void {
            $this->runner()->upgrade($manifest, $this->loadRoot, $manifest['migrations'] ?? 'migrations');
        };
    }

    /**
     * 升级用槽位：将包升级到 manifest.version，执行新增未执行的 up。
     */
    public function upgradeHandler(): callable
    {
        return function (array $manifest): void {
            $this->runner()->upgrade($manifest, $this->loadRoot, $manifest['migrations'] ?? 'migrations');
        };
    }

    /**
     * 回退用槽位：执行某版本目录的全部 down（撤销该版本 up 的结构变化）。
     *
     * @return callable (array{version:string}): void
     */
    public function rollbackHandler(): callable
    {
        return function (array $input): void {
            $short = (string) ($input['short'] ?? '');
            $from = (string) ($input['version'] ?? '');
            if ($from === '' || $short === '') {
                throw new \RuntimeException('回退需要 short 与 version');
            }
            $sub = (string) ($input['migrations'] ?? 'migrations');
            $this->runner()->rollback($input, $this->loadRoot, $from, $sub);
        };
    }
}