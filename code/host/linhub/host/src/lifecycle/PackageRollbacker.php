<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 回退器（T2.5 / G5 / Q3/Q4）
 *
 * 从指定备份（默认最近一次）还原源码到目标目录，并把版本记录更新为该备份内 manifest 的版本。
 * 回退不改变 enabled/disabled 状态。迁移（down）为可注入槽位（S3），null 时跳过并记录 pending。
 */
final class PackageRollbacker
{
    /** @var PackageStateStore */
    private PackageStateStore $store;
    /** @var BackupManager */
    private BackupManager $backup;
    /** @var string 宿主加载目录 */
    private string $loadRoot;
    /** @var callable|null */
    private $migrateDown;

    /**
     * @param callable|null $migrateDown (array $manifest): void  执行 down 迁移
     */
    public function __construct(
        PackageStateStore $store,
        BackupManager $backup,
        string $loadRoot,
        ?callable $migrateDown = null,
    ) {
        $this->store = $store;
        $this->backup = $backup;
        $this->loadRoot = rtrim($loadRoot, '/\\');
        $this->migrateDown = $migrateDown;
    }

    /**
     * 回退功能包到备份版本。
     *
     * @param string $name 功能包名
     * @param string|null $backupPath 指定备份路径；默认使用最近一次备份
     * @return array{record:array<string,mixed>, steps:list<array{step:string,status:string,note:string}>}
     * @throws \RuntimeException 无记录/无备份/回退失败
     */
    public function rollback(string $name, ?string $backupPath = null): array
    {
        $rec = $this->store->get($name);
        if ($rec === null) {
            throw new \RuntimeException("功能包未安装，无法回退：{$name}");
        }
        $backupPath = $backupPath ?? $this->backup->latest($name);
        if ($backupPath === null) {
            throw new \RuntimeException("功能包没有可用备份：{$name}");
        }
        $target = is_dir($this->loadRoot . '/' . ($rec['short'] ?? ''))
            ? $this->loadRoot . '/' . ($rec['short'] ?? '')
            : (string) $rec['source_dir'];
        $steps = [];

        try {
            // 还原源码
            $this->backup->restore($backupPath, $target);
            $steps[] = ['step' => 'restore', 'status' => 'ok', 'note' => $backupPath];

            // 从备份 manifest 识别目标版本
            $version = $rec['version'];
            $mf = rtrim($backupPath, '/\\') . '/manifest.php';
            if (is_file($mf)) {
                $m = include $mf;
                $version = (string) ($m['version'] ?? $rec['version']);
            }
            $steps[] = ['step' => 'version', 'status' => 'ok', 'note' => $version];

            // 迁移 down（槽位）
            $down = 'pending';
            if ($this->migrateDown !== null) {
                ($this->migrateDown)(['version' => $version]);
                $down = 'ok';
            }
            $steps[] = ['step' => 'migrate_down', 'status' => $down, 'note' => $down === 'ok' ? '' : '槽位未注入(S3)'];

            // 更新版本记录，保持状态
            $newRec = array_merge($rec, ['version' => $version, 'source_dir' => $target, 'backup_dir' => $backupPath]);
            $this->store->save($newRec);

            return ['record' => $newRec, 'steps' => $steps];
        } catch (\Throwable $e) {
            throw new \RuntimeException('回退失败：' . $e->getMessage(), 0, $e);
        }
    }
}