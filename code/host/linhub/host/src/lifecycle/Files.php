<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 文件系统工具（生命周期组件共用）
 *
 * 提供目录递归复制与删除，供备份/安装/升级/回退/删除复用。
 */
final class Files
{
    private function __construct()
    {
    }

    /** 递归复制目录（含空目录与文件）。 */
    public static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = rtrim($src, '/\\') . '/' . $entry;
            $d = rtrim($dst, '/\\') . '/' . $entry;
            is_dir($s) ? self::copyDir($s, $d) : copy($s, $d);
        }
    }

    /** 递归删除目录（对 Windows 目录删除时机做重试）。 */
    public static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = rtrim($dir, '/\\') . '/' . $entry;
            is_dir($p) ? self::deleteTree($p) : @unlink($p);
        }
        clearstatcache(true, $dir);
        // Windows 在 unlink 后目录句柄可能未即时刷新，rmdir 偶发 "Directory not empty"
        for ($i = 0; $i < 3; $i++) {
            if (@rmdir($dir)) {
                return;
            }
            usleep(50_000);
            clearstatcache(true, $dir);
        }
    }
}