<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 升级器（T2.4 / G3 / Q）
 *
 * 备份旧版 → 替换为新版源码 → 更新版本记录。升级不改变 enabled/disabled 状态。
 * 迁移（up）与接入（integrate）为可注入槽位（S3/S4），null 时跳过并记录 pending。
 * 失败可回退（本次失败时尝试从 pre-upgrade 备份还原现场后抛出）。
 */
final class PackageUpgrader
{
    /** @var PackageStateStore */
    private PackageStateStore $store;
    /** @var BackupManager */
    private BackupManager $backup;
    /** @var string 宿主加载目录 */
    private string $loadRoot;
    /** @var string 宿主版本 */
    private string $hostVersion;
    /** @var callable|null */
    private $migrate;
    /** @var callable|null */
    private $integrate;
    /** @var PlatformChecker */
    private PlatformChecker $checker;
    /** @var DependencyResolver */
    private DependencyResolver $resolver;

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
        $this->checker = new PlatformChecker($hostVersion);
        $this->resolver = new DependencyResolver($store);
    }

    /**
     * 升级功能包。
     *
     * @param string $name 功能包名
     * @param string $newPackageDir 新版功能包源码目录
     * @return array{record:array<string,mixed>, steps:list<array{step:string,status:string,note:string}>}
     * @throws \RuntimeException 校验失败或升级失败（已尽力回退）
     */
    public function upgrade(string $name, string $newPackageDir): array
    {
        $rec = $this->store->get($name);
        if ($rec === null) {
            throw new \RuntimeException("功能包未安装，无法升级：{$name}");
        }
        $state = (string) ($rec['state'] ?? '');
        // 仅 enabled/disabled 可升级；升级保持原状态
        if (!in_array($state, [LifecycleState::ENABLED, LifecycleState::DISABLED], true)) {
            throw new \RuntimeException("当前状态 {$state} 不允许升级");
        }

        $newManifest = include rtrim($newPackageDir, '/\\') . '/manifest.php';

        // 升级前平台兼容
        $platformErr = $this->checker->check((array) ($newManifest['platform'] ?? []));
        if ($platformErr !== null) {
            throw new \RuntimeException('升级被拦截：' . $platformErr);
        }
        // 服务契约依赖硬拦截（Upgrader 不经 integrity，此为唯一拦截点；在备份之前，零副作用）
        $serviceErr = (new ServiceDependencyChecker())->check((array) ($newManifest['services']['requires'] ?? []));
        if ($serviceErr !== null) {
            throw new \RuntimeException('升级被拦截：' . $serviceErr);
        }
        // 依赖解析（用新版清单）
        $uv = $this->resolver->resolve($name, (array) ($newManifest['dependencies'] ?? []));
        if ($uv['missing'] !== [] || $uv['conflict'] !== [] || $uv['cycle']) {
            throw new \RuntimeException('升级被拦截：新版本依赖不满足');
        }

        $newSource = $newPackageDir;
        // 禁用中的包源码在隔离区：备份/替换都以实际源码位置为准，升级后仍落在隔离区（保持"禁用=不可用"）
        $landed = $this->loadRoot . '/' . ($rec['short'] ?? '');
        if ($state === LifecycleState::DISABLED && is_dir((string) ($rec['disabled_dir'] ?? ''))) {
            $landed = (string) $rec['disabled_dir'];
        }
        $oldSource = is_dir($landed) ? $landed : (string) $rec['source_dir'];
        $steps = [];

        try {
            // 备份旧版
            $preBackup = $this->backup->snapshot($oldSource, $name, 'pre-upgrade');
            $steps[] = ['step' => 'backup_old', 'status' => 'ok', 'note' => $preBackup];

            // 替换为新版源码
            if (is_dir($landed)) {
                Files::deleteTree($landed);
            }
            Files::copyDir($newSource, $landed);
            $steps[] = ['step' => 'replace', 'status' => 'ok', 'note' => $landed];

            // 迁移（槽位）
            $ups = 'pending';
            if ($this->migrate !== null) {
                ($this->migrate)($newManifest);
                $ups = 'ok';
            }
            $steps[] = ['step' => 'migrate', 'status' => $ups, 'note' => $ups === 'ok' ? '' : '槽位未注入(S3)'];

            // 接入（槽位）
            $ints = 'pending';
            if ($this->integrate !== null) {
                ($this->integrate)($newManifest);
                $ints = 'ok';
            }
            $steps[] = ['step' => 'integrate', 'status' => $ints, 'note' => $ints === 'ok' ? '' : '槽位未注入(S4)'];

            // 更新版本/时间记录，状态保持不变；source_dir 始终记录加载目录（语义位置，禁用包实际源码在 disabled_dir）
            $newRec = array_merge($rec, [
                'version'   => (string) ($newManifest['version'] ?? $rec['version']),
                'source_dir' => $this->loadRoot . '/' . ($rec['short'] ?? ''),
                'backup_dir' => $preBackup,
                'dependencies' => (array) ($newManifest['dependencies'] ?? []),
            ]);
            $newRec['installed_at'] = $rec['installed_at']; // 保留首次安装时间
            $this->store->save($newRec);
            $steps[] = ['step' => 'record', 'status' => 'ok', 'note' => $newRec['state']];

            return ['record' => $newRec, 'steps' => $steps];
        } catch (\Throwable $e) {
            // 失败回退：尝试从最新备份还原现场
            $latest = $this->backup->latest($name);
            if (is_dir($landed) && $latest !== null) {
                $this->backup->restore($latest, $landed);
            }
            throw new \RuntimeException('升级失败已回退：' . $e->getMessage(), 0, $e);
        }
    }
}