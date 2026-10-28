<?php

declare(strict_types=1);

namespace linhub\host\migration;

use linhub\host\lifecycle\BackupManager;

/**
 * 备份引擎（T3.7 / Q / B5）
 *
 * 在安装/升级前提供可配置的备份能力（源码 / 文件 / 库结构可配参数）。
 * 复用 S2 已实现的 {@see BackupManager}（源码快照 + 还原），在此基础上叠加：
 * - 可配置策略 enableSource / enableSchema；
 * - 库结构快照写入备份目录 schema.json（结构化、可还原比对）；
 * 归并策略：源码/文件备份完全委托 BackupManager，库结构备份由本类负责并写入同一备份目录。
 */
final class BackupEngine
{
    /** @var BackupManager */
    private BackupManager $backup;

    /** @var bool 是否备份源码目录 */
    private bool $enableSource;

    /** @var bool 是否备份库结构快照 */
    private bool $enableSchema;

    /** @var string 备份根目录 */
    private string $root;

    /**
     * @param bool $enableSource 是否快照源码目录
     * @param bool $enableSchema 是否生成并保存库结构快照
     */
    public function __construct(
        string $backupRoot,
        bool $enableSource = true,
        bool $enableSchema = true,
    ) {
        $this->root = rtrim($backupRoot, '/\\');
        $this->backup = new BackupManager($this->root);
        $this->enableSource = $enableSource;
        $this->enableSchema = $enableSchema;
    }

    public function roots(): string
    {
        return $this->root;
    }

    /**
     * 备份前执行：快照源码（可选）并写入 schema.json。
     *
     * @param string $sourceDir 功能包源码目录（源码落地后、迁移前）
     * @param string $name 包名
     * @param string $tag 标签（pre-install / pre-upgrade / manual）
     * @param callable|null $captor 库结构快照捕获回调 () : array；启用 schema 且非 null 时调用
     * @return array{backupPath:string,schemaSnap:?array}
     */
    public function beforeInstall(string $sourceDir, string $name, string $tag, ?callable $captor = null): array
    {
        $backupPath = $this->enableSource
            ? $this->backup->snapshot($sourceDir, $name, $tag)
            : $this->backup->root() . '/' . $name . '/' . $tag . '_nosource';
        $schemaSnap = null;
        if ($this->enableSchema && $captor !== null) {
            $schemaSnap = $captor();
            $this->writeSchemaFile($backupPath, $schemaSnap);
        }
        return ['backupPath' => $backupPath, 'schemaSnap' => $schemaSnap];
    }

    public function writeSchemaFile(string $backupPath, array $snapshot): void
    {
        $dir = rtrim($backupPath, '/\\');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/schema.json',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    /** 最近一次备份路径。 */
    public function latest(string $name): ?string
    {
        return $this->backup->latest($name);
    }

    /**
     * 还原源码（委托 BackupManager）。
     *
     * @param string $backupPath 备份路径
     * @param string $targetDir 目标源码目录
     */
    public function restoreSource(string $backupPath, string $targetDir): void
    {
        $this->backup->restore($backupPath, $targetDir);
    }

    /** 读取备份内的库结构快照（schema.json），不存在返回 null。 */
    public function readSchema(string $backupPath): ?array
    {
        $file = rtrim($backupPath, '/\\') . '/schema.json';
        if (!is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}