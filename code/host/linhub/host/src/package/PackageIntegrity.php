<?php

declare(strict_types=1);

namespace linhub\host\package;

use linhub\host\manifest\ManifestValidator;

/**
 * 功能包完整性校验（T1.5 / G4）
 *
 * 在安装/加载前校验包的完整性、格式与清单一致性：
 *  - 结构必备项存在（manifest.php、composer.json、src）。
 *  - manifest 可解析且通过合法校验（T1.1 M001–M011）。
 *  - manifest 中的 `short` 与目录名 `lgg-pkg-<short>` 一致。
 * 篡改/损坏的包装别并给出区分性错误，由调用方决定是否阻断。
 */
final class PackageIntegrity
{
    /**
     * 校验功能包目录，返回检测到的问题描述列表（空则通过）。
     *
     * @param string $packageDir 功能包目录
     * @return list<string>
     */
    public static function check(string $packageDir): array
    {
        $problems = [];
        if (!is_dir($packageDir)) {
            return ["功能包目录不存在：{$packageDir}"];
        }

        $manifestFile = rtrim($packageDir, '/\\') . '/manifest.php';
        if (!is_file($manifestFile)) {
            $problems[] = '缺少 manifest.php';
            return $problems;
        }

        // manifest 解析
        $manifest = @include $manifestFile;
        if (!is_array($manifest)) {
            $problems[] = 'manifest.php 未返回数组（文件损坏或语法错误）';
            return $problems;
        }

        // 清单一致性：short 与目录名
        $short = (string) ($manifest['short'] ?? '');
        $dirName = basename(rtrim($packageDir, '/\\'));
        if ($short !== '' && $dirName !== "lgg-pkg-{$short}") {
            $problems[] = "目录名与 manifest.short 不一致：目录={$dirName}，short={$short}";
        }

        // 必需结构
        foreach (['composer.json', 'src'] as $required) {
            if (!file_exists(rtrim($packageDir, '/\\') . '/' . $required)) {
                $problems[] = "缺少必需结构：{$required}";
            }
        }

        // manifest 合法校验
        $manifestErrors = (new ManifestValidator())->validate($manifest);
        foreach ($manifestErrors as $code) {
            $problems[] = "manifest 校验失败：{$code}";
        }

        return $problems;
    }
}