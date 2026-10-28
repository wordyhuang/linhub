<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

use linhub\host\package\PackageIntegrity;

/**
 * 安装器（T2.1 / G2 / A3-A5）
 *
 * 编排安装流程：完整性校验 → 依赖解析 → 平台兼容 → 备份 → 落地源码到宿主加载目录 → 迁移(槽位) → 接入权限/菜单/界面(槽位) → 状态迁移至 enabled。
 * 任一步失败整体回滚（已落地文件清理、状态机不动、不写记录）。
 * 迁移（migrate）与接入（integrate）为可注入的可调用槽位（S3/S4）：
 *   传入 null 时跳过并记录步骤为 pending；传入可调用时执行，抛异常则触发回滚。
 */
final class PackageInstaller
{
    /** @var PackageStateStore */
    private PackageStateStore $store;
    /** @var BackupManager */
    private BackupManager $backup;
    /** @var string 宿主加载目录（源码落地根） */
    private string $loadRoot;
    /** @var string 宿主版本 */
    private string $hostVersion;
    /** @var callable|null 迁移槽位（S3） */
    private $migrate;
    /** @var callable|null 接入权限/菜单/界面槽位（S4） */
    private $integrate;
    /** @var DependencyResolver */
    private DependencyResolver $resolver;
    /** @var PlatformChecker */
    private PlatformChecker $checker;

    /**
     * @param callable|null $migrate (array $manifest): void
     * @param callable|null $integrate (array $manifest): void
     */
    public function __construct(
        PackageStateStore $store,
        BackupManager $backup,
        string $loadRoot,
        string $hostVersion,
        ?callable $migrate = null,
        ?callable $integrate = null,
    ) {
        $this->store = $store;
        $this->backup = $backup;
        $this->loadRoot = rtrim($loadRoot, '/\\');
        $this->hostVersion = $hostVersion;
        $this->migrate = $migrate;
        $this->integrate = $integrate;
        $this->resolver = new DependencyResolver($store);
        $this->checker = new PlatformChecker($hostVersion);
    }

    /**
     * 安装功能包。
     *
     * @param string $packageDir 功能包源码目录（含 manifest.php）
     * @return array{record:array<string,mixed>, steps:list<array{step:string,status:string,note:string}>}
     * @throws \RuntimeException 任一步骤失败，整体回滚
     */
    public function install(string $packageDir): array
    {
        $steps = [];
        $landed = null; // 记录是否已落地及路径，用于回滚

        // 步骤一：完整性校验
        $problems = PackageIntegrity::check($packageDir);
        if ($problems !== []) {
            throw new \RuntimeException('完整性校验失败：' . implode('; ', $problems));
        }
        $steps[] = ['step' => 'integrity', 'status' => 'ok', 'note' => ''];
        $manifest = include rtrim($packageDir, '/\\') . '/manifest.php';
        $name = (string) ($manifest['name'] ?? '');
        $short = (string) ($manifest['short'] ?? '');
        $version = (string) ($manifest['version'] ?? '');

        if ($this->store->has($name)) {
            throw new \RuntimeException("功能包已安装：{$name}");
        }
        $steps[] = ['step' => 'check_duplicate', 'status' => 'ok', 'note' => ''];

        // 步骤二：依赖解析
        $deps = (array) ($manifest['dependencies'] ?? []);
        $uv = $this->resolver->resolve($name, $deps);
        if ($uv['missing'] !== []) {
            throw new \RuntimeException('安装被拦截：缺少依赖 ' . implode(',', $uv['missing']));
        }
        if ($uv['conflict'] !== []) {
            $msg = implode('; ', array_map(fn ($c) => "{$c['name']} 需要 {$c['required']} 但已装 {$c['installed']}", $uv['conflict']));
            throw new \RuntimeException('安装被拦截：依赖冲突 ' . $msg);
        }
        if ($uv['cycle']) {
            throw new \RuntimeException('安装被拦截：依赖环');
        }
        $steps[] = ['step' => 'dependency', 'status' => 'ok', 'note' => ''];

        // 步骤三：平台兼容
        $platformErr = $this->checker->check((array) ($manifest['platform'] ?? []));
        if ($platformErr !== null) {
            throw new \RuntimeException('安装被拦截：' . $platformErr);
        }
        $steps[] = ['step' => 'platform', 'status' => 'ok', 'note' => $this->hostVersion];

        // 步骤三·五：服务契约依赖硬拦截（备份/落地之前，零副作用）
        $serviceErr = (new ServiceDependencyChecker())->check((array) ($manifest['services']['requires'] ?? []));
        if ($serviceErr !== null) {
            throw new \RuntimeException('安装被拦截：' . $serviceErr);
        }
        $steps[] = ['step' => 'service_deps', 'status' => 'ok', 'note' => ''];

        try {
            // 步骤四：备份（安装前快照）
            $backupPath = $this->backup->snapshot($packageDir, $name, 'pre-install');
            $steps[] = ['step' => 'backup', 'status' => 'ok', 'note' => $backupPath];

            // 步骤五：落地源码到宿主加载目录
            $landed = $this->loadRoot . '/' . $short;
            if (is_dir($landed)) {
                Files::deleteTree($landed);
            }
            Files::copyDir($packageDir, $landed);
            $steps[] = ['step' => 'land', 'status' => 'ok', 'note' => $landed];

            // 步骤六：迁移（槽位）
            $migrateStatus = 'pending';
            $migrateNote = '槽位未注入，跳过(S3)';
            if ($this->migrate !== null) {
                ($this->migrate)($manifest);
                $migrateStatus = 'ok';
                $migrateNote = '';
            }
            $steps[] = ['step' => 'migrate', 'status' => $migrateStatus, 'note' => $migrateNote];

            // 步骤七：接入权限/菜单/界面（槽位）
            $integrateStatus = 'pending';
            $integrateNote = '槽位未注入，跳过(S4)';
            if ($this->integrate !== null) {
                ($this->integrate)($manifest);
                $integrateStatus = 'ok';
                $integrateNote = '';
            }
            $steps[] = ['step' => 'integrate', 'status' => $integrateStatus, 'note' => $integrateNote];

            // 步骤八：状态迁移 absent -> enabled
            $state = LifecycleState::transition(LifecycleState::ABSENT, LifecycleState::ENABLED);
            $record = [
                'name'         => $name,
                'short'        => $short,
                'version'      => $version,
                'state'        => $state,
                'installed_at' => date('c'),
                'source_dir'   => $landed,
                'backup_dir'   => $backupPath,
                'dependencies' => $deps,
            ];
            $this->store->save($record);
            $steps[] = ['step' => 'state', 'status' => 'ok', 'note' => $state];

            return ['record' => $record, 'steps' => $steps];
        } catch (\Throwable $e) {
            // 整体回滚：清理已落地文件；状态机不动（尚未写记录）
            if ($landed !== null && is_dir($landed)) {
                Files::deleteTree($landed);
            }
            throw new \RuntimeException('安装失败已回滚：' . $e->getMessage(), 0, $e);
        }
    }
}