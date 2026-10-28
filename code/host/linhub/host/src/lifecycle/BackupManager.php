<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 独立备份操作（T2.9 + 备份引擎基础 / G9 / Q6）
 *
 * 将功能包当前源码目录快照到备份目录（带时间戳），返回备份路径。
 * 提供列表与 restore（还原到目标目录）。不改变功能状态。
 * 备份路径约定：<backupRoot>/<name>/<tag>_<时间戳>/
 * 每个备份子目录内含 manifest.php（用于还原时识别版本）。
 */
final class BackupManager
{
    /** @var string 备份根目录 */
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    /** 备份根目录。 */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * 快照源码目录到备份目录，返回备份路径。
     *
     * @param string $sourceDir 源目录
     * @param string $name 功能包名（用于子目录隔离）
     * @param string $tag 备份标签（manual 手动 / pre-upgrade 升级前 / pre-install 安装前）
     */
    public function snapshot(string $sourceDir, string $name, string $tag = 'manual'): string
    {
        $stamp = date('Ymd_His') . '_' . substr((string) microtime(true), -2);
        $dst = $this->root . '/' . $name . '/' . $tag . '_' . $stamp;
        if (!is_dir($sourceDir)) {
            throw new \RuntimeException("备份源目录不存在：{$sourceDir}");
        }
        Files::copyDir($sourceDir, $dst);
        return $dst;
    }

    /**
     * 列出某功能包的备份记录（按时间升序）。
     *
     * @return list<array{path:string, tag:string}>
     */
    public function list(string $name): array
    {
        $dir = $this->root . '/' . $name;
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $tag = ($pos = strpos($entry, '_')) !== false ? substr($entry, 0, $pos) : $entry;
            $out[] = ['path' => $path, 'tag' => $tag];
        }
        usort($out, fn ($a, $b) => strcmp($a['path'], $b['path']));
        return $out;
    }

    /** 获取某功能包最近一次备份路径，无返回 null。 */
    public function latest(string $name): ?string
    {
        $list = $this->list($name);
        return $list === [] ? null : end($list)['path'];
    }

    /**
     * 从指定备份还原到目标目录（目标已存在则先清空）。
     *
     * @param string $backupPath 备份路径
     * @param string $targetDir 目标目录
     */
    public function restore(string $backupPath, string $targetDir): void
    {
        if (!is_dir($backupPath)) {
            throw new \RuntimeException("备份不存在：{$backupPath}");
        }
        if (is_dir($targetDir)) {
            Files::deleteTree($targetDir);
        }
        Files::copyDir($backupPath, $targetDir);
    }
}