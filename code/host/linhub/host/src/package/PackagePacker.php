<?php

declare(strict_types=1);

namespace linhub\host\package;

use linhub\host\manifest\ManifestError;
use linhub\host\manifest\ManifestValidator;

/**
 * 功能包打包器（T1.3 / E3 / G1）
 *
 * 将源码 + manifest + 资源按《功能包协议规范》目录结构打包为可安装交付单元。
 * 产物支持两种形态：zip（系统装有 ext-zip 时）或标准目录副本（始终可用）。
 * 入包前对 manifest 做一次校验，保证产物内含完整合法清单（G1）。
 *
 * 数据随包（P1）：源目录顶层 data/ 为数据随包导出产物目录，不入源扫描；
 * 由调用方经 PlatformDataPacker 生成文件集后以 $extraFiles（相对路径 => 内容）注入包内。
 */
final class PackagePacker
{
    /** 打包时应包含的顶层交付文件/目录。 */
    private const DELIVERABLE = [
        'manifest.php',
        'composer.json',
        'permissions.php',
        'menus.php',
        'workspace.php',
        'ui_replace.php',
        'shortcuts.php',
        'tasks.php',
        'src',
        'migrations',
        'resources',
        'README.md',
    ];

    /**
     * 把功能包源码目录打包为 zip 文件。
     *
     * @param string $packageDir 功能包源码目录（含 manifest.php）
     * @param string $outputDir  输出目录
     * @param string|null $filename 产物文件名；缺省 lgg-pkg-{short}-v{version}.zip
     * @param array<string,string> $extraFiles 附加注入文件（相对包根路径 => 内容），如数据随包导出产物
     * @return string 生成的 zip 绝对路径
     * @throws \RuntimeException 缺少 manifest 或校验失败、ext-zip 不可用
     */
    public static function pack(string $packageDir, string $outputDir, ?string $filename = null, array $extraFiles = []): string
    {
        $manifest = self::readManifest($packageDir);
        $reasons = [];
        $errors = (new ManifestValidator())->validate($manifest, $reasons, $packageDir);
        if ($errors !== []) {
            throw new \RuntimeException('打包前 manifest 校验失败：' . implode(',', ManifestError::render($errors, $reasons)));
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ext-zip 未启用，无法生成 zip（可用 packToDir 生成目录包）');
        }

        if ($outputDir !== '' && !is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("无法创建输出目录：{$outputDir}");
        }

        $short = (string) ($manifest['short'] ?? 'pkg');
        $name = $filename ?? "lgg-pkg-{$short}-v{$manifest['version']}.zip";
        $zipPath = rtrim($outputDir, '/\\') . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("无法打开 zip 文件：{$zipPath}");
        }

        self::addDir($zip, $packageDir, '');
        // 附加注入文件（数据随包导出产物等）：路径分隔统一为 /
        foreach ($extraFiles as $rel => $content) {
            $zip->addFromString(ltrim(str_replace('\\', '/', (string) $rel), '/'), (string) $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * 生成标准目录包（不依赖更改进 zip 扩展）。
     *
     * @param array<string,string> $extraFiles 附加注入文件（相对包根路径 => 内容），如数据随包导出产物
     * @return string 目标目录绝对路径
     */
    public static function packToDir(string $packageDir, string $targetDir, array $extraFiles = []): string
    {
        $manifest = self::readManifest($packageDir);
        $reasons = [];
        $errors = (new ManifestValidator())->validate($manifest, $reasons, $packageDir);
        if ($errors !== []) {
            throw new \RuntimeException('打包前 manifest 校验失败：' . implode(',', ManifestError::render($errors, $reasons)));
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("无法创建目标目录：{$targetDir}");
        }

        foreach (self::listSourceItems($packageDir, true) as $item) {
            $src = rtrim($packageDir, '/\\') . '/' . $item;
            $dst = rtrim($targetDir, '/\\') . '/' . $item;
            if (is_dir($src)) {
                self::copyDir($src, $dst);
            } elseif (is_file($src)) {
                self::ensureParent($dst);
                copy($src, $dst);
            }
        }
        // 附加注入文件（数据随包导出产物等）：路径分隔统一为 /
        foreach ($extraFiles as $rel => $content) {
            $dst = rtrim($targetDir, '/\\') . '/' . ltrim(str_replace('\\', '/', (string) $rel), '/');
            self::ensureParent($dst);
            file_put_contents($dst, (string) $content);
        }
        return $targetDir;
    }

    private static function readManifest(string $packageDir): array
    {
        $manifestFile = rtrim($packageDir, '/\\') . '/manifest.php';
        if (!is_file($manifestFile)) {
            throw new \RuntimeException("缺少 manifest.php：{$manifestFile}");
        }
        $m = include $manifestFile;
        if (!is_array($m)) {
            throw new \RuntimeException('manifest.php 必须返回数组');
        }
        return $m;
    }

    /** 列出要入包的顶层交付项（过滤运行时产物）。 */
    private static function listSourceItems(string $packageDir, bool $excludeData = false): array
    {
        $items = [];
        foreach (scandir($packageDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'vendor') {
                continue;
            }
            // 顶层 data/ 为数据随包导出产物目录（由 PlatformDataPacker 生成、经 $extraFiles 注入），不入源扫描
            if ($excludeData && $entry === 'data') {
                continue;
            }
            $items[] = $entry;
        }
        // 按交付清单排序，非清单项置后
        usort($items, fn ($a, $b) => ((int) !in_array($a, self::DELIVERABLE, true)) - ((int) !in_array($b, self::DELIVERABLE, true)));
        return $items;
    }

    private static function addDir(\ZipArchive $zip, string $baseDir, string $relative): void
    {
        // 仅顶层排除 data/（递归子目录中同名目录不受影响，避免误伤 resources/data 等静态资源）
        foreach (self::listSourceItems($baseDir, $relative === '') as $item) {
            $full = rtrim($baseDir, '/\\') . '/' . $item;
            $rel = $relative === '' ? $item : $relative . '/' . $item;
            if (is_dir($full)) {
                $zip->addEmptyDir($rel);
                self::addDir($zip, $full, $rel);
            } elseif (is_file($full)) {
                $zip->addFile($full, $rel);
            }
        }
    }

    private static function ensureParent(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private static function copyDir(string $src, string $dst): void
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
            if (is_dir($s)) {
                self::copyDir($s, $d);
            } else {
                copy($s, $d);
            }
        }
    }
}