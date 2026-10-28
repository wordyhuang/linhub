<?php

declare(strict_types=1);

namespace linhub\host\metrics;

use think\App;

/**
 * 服务调用统计存储（累计次数 / 最近调用时间 / 最近调用 URL）。
 *
 * 统计维度：被调用方（服务 id + host|package 来源 + 提供方功能包）、方法、调用方功能包。
 * 键为 "source|callee|method|caller"，请求内先累积在内存，进程结束时合并落盘，
 * 避免每次方法调用都写文件；落盘使用 flock 读改写，保证多进程并发不丢计数。
 *
 * 数据文件：code/host/data/metrics/service-calls.json
 */
final class ServiceCallStatStore
{
    /** @var array<string, array<string, mixed>> 本次请求累积的调用计数 */
    private static array $buffer = [];

    /** 是否已注册关机落盘回调。 */
    private static bool $shutdownRegistered = false;

    /** 当前请求 URL 缓存（同一请求内不变）。 */
    private static ?string $url = null;

    /** @var array<string, string>|null 包服务 id => 提供方包短名 缓存 */
    private static ?array $owners = null;

    private function __construct()
    {
    }

    /**
     * 记录一次服务方法调用。
     *
     * @param string      $callee     被调用服务 id
     * @param string      $source     服务来源 host|package
     * @param string|null $callerHint 调用方功能包 id（已知时直接传入，未知时按调用栈推断）
     * @param string      $method     接口/方法名
     * @param string|null $url        调用发生的请求 URL（缺省取当前请求）
     */
    public static function record(string $callee, string $source, ?string $callerHint, string $method, ?string $url = null): void
    {
        $caller = self::resolveCaller($callerHint);
        $now    = date('Y-m-d H:i:s');
        $url    = $url ?? self::currentUrl();
        $key    = $source . '|' . $callee . '|' . $method . '|' . $caller;

        if (!isset(self::$buffer[$key])) {
            self::$buffer[$key] = [
                'callee'  => $callee,
                'source'  => $source,
                'owner'   => self::ownerOf($callee, $source),
                'caller'  => $caller,
                'method'  => $method,
                'count'   => 0,
                'lastAt'  => $now,
                'lastUrl' => $url,
            ];
        }
        self::$buffer[$key]['count']++;
        self::$buffer[$key]['lastAt']  = $now;
        self::$buffer[$key]['lastUrl'] = $url;

        self::registerFlush();
    }

    /** 解析调用方：优先使用显式传入的功能包 id，否则从调用栈推断，兜底记为 host。 */
    public static function resolveCaller(?string $callerHint): string
    {
        $hint = trim((string) $callerHint);
        if ($hint !== '') {
            return str_starts_with($hint, 'pkg.') ? substr($hint, 4) : $hint;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file  = (string) ($frame['file'] ?? '');
            $short = self::packageFromFile($file);
            if ($short !== null) {
                return $short;
            }
            // 宿主管理端（admin 应用）内的调用记 admin
            if (str_contains(str_replace('\\', '/', $file), '/linhub/admin/')) {
                return 'admin';
            }
        }
        return 'host';
    }

    /** 当前请求 URL（含查询串）；CLI 场景返回 cli。 */
    public static function currentUrl(): string
    {
        if (self::$url !== null) {
            return self::$url;
        }
        if (PHP_SAPI === 'cli') {
            return self::$url = 'cli';
        }
        try {
            $request = App::getInstance()->request;
            self::$url = $request === null ? '' : (string) $request->url();
        } catch (\Throwable) {
            self::$url = '';
        }
        return self::$url;
    }

    /** 全部统计，按调用次数降序。 */
    public static function all(): array
    {
        $data = self::load();
        foreach (self::$buffer as $key => $row) {
            if (!isset($data[$key])) {
                $data[$key] = $row;
                continue;
            }
            $data[$key]['count'] = (int) ($data[$key]['count'] ?? 0) + (int) $row['count'];
            if (strcmp((string) $row['lastAt'], (string) ($data[$key]['lastAt'] ?? '')) >= 0) {
                $data[$key]['lastAt']  = $row['lastAt'];
                $data[$key]['lastUrl'] = $row['lastUrl'];
            }
        }

        $list = array_values($data);
        usort($list, static function (array $a, array $b): int {
            return ((int) $b['count'] <=> (int) $a['count'])
                ?: strcmp((string) $a['callee'], (string) $b['callee'])
                ?: strcmp((string) $a['method'], (string) $b['method'])
                ?: strcmp((string) $a['caller'], (string) $b['caller']);
        });
        return $list;
    }

    /** 把本次请求累积的计数合并写入统计文件。 */
    public static function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }
        $file = self::file();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $raw  = stream_get_contents($handle);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            foreach (self::$buffer as $key => $row) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    $data[$key] = $row;
                    continue;
                }
                $data[$key]['count'] = (int) ($data[$key]['count'] ?? 0) + (int) $row['count'];
                if (strcmp((string) $row['lastAt'], (string) ($data[$key]['lastAt'] ?? '')) >= 0) {
                    $data[$key]['lastAt']  = $row['lastAt'];
                    $data[$key]['lastUrl'] = $row['lastUrl'];
                }
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);
            self::$buffer = [];
        } finally {
            fclose($handle);
        }
    }

    /** 统计文件绝对路径：code/host/data/metrics/service-calls.json。 */
    public static function file(): string
    {
        return self::dataRoot() . DIRECTORY_SEPARATOR . 'metrics' . DIRECTORY_SEPARATOR . 'service-calls.json';
    }

    /** 读取统计文件（不合并内存计数）。 */
    private static function load(): array
    {
        $file = self::file();
        if (!is_file($file)) {
            return [];
        }
        $raw  = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** 注册关机落盘（每个进程只注册一次）。 */
    private static function registerFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'flush']);
    }

    /** 从文件路径识别功能包短名；宿主文件返回 null。 */
    private static function packageFromFile(string $file): ?string
    {
        if ($file === '') {
            return null;
        }
        $file = str_replace('\\', '/', $file);
        if (preg_match('#/code/plugins/([^/]+)/#', $file, $m)) {
            return $m[1];
        }
        if (preg_match('#/package/staging/lgg-pkg-([^/]+)/#', $file, $m)) {
            return $m[1];
        }
        if (preg_match('#/data/plugin-packout/lgg-pkg-([^/]+)/#', $file, $m)) {
            return $m[1];
        }
        return null;
    }

    /** 包服务提供方（包短名）；host 服务返回空串。 */
    private static function ownerOf(string $callee, string $source): string
    {
        if ($source !== 'package') {
            return '';
        }
        if (self::$owners === null) {
            self::$owners = self::buildOwners();
        }
        return self::$owners[$callee] ?? '';
    }

    /** 扫描已启用包 manifest 的 provides，建立 服务 id => 包短名 映射。 */
    private static function buildOwners(): array
    {
        $map = [];

        $stateFile = self::dataRoot() . DIRECTORY_SEPARATOR . 'plugin-state.json';
        $raw       = is_file($stateFile) ? @file_get_contents($stateFile) : false;
        foreach ((array) ($raw === false ? [] : (json_decode($raw, true) ?: [])) as $rec) {
            if ((string) ($rec['state'] ?? '') !== 'enabled') {
                continue;
            }
            $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($rec['source_dir'] ?? ''));
            if ($dir === '') {
                continue;
            }
            self::collectProvides($map, (string) ($rec['short'] ?? ''), $dir . DIRECTORY_SEPARATOR . 'manifest.php');
        }

        // 兜底：直接扫描 plugins 目录（未登记 state 的开发态包）
        foreach (glob(self::pluginsRoot() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.php') ?: [] as $file) {
            self::collectProvides($map, basename(dirname($file)), $file);
        }
        return $map;
    }

    private static function collectProvides(array &$map, string $short, string $manifestFile): void
    {
        if (!is_file($manifestFile)) {
            return;
        }
        $manifest = @include $manifestFile;
        if (!is_array($manifest)) {
            return;
        }
        $short = (string) ($manifest['short'] ?? $short);
        foreach ((array) ($manifest['services']['provides'] ?? []) as $item) {
            $item = (array) $item;
            $id   = (string) ($item['id'] ?? '');
            if ($id !== '' && $short !== '') {
                $map[$id] = $short;
            }
        }
    }

    /** 管理数据根目录：code/host/data。 */
    private static function dataRoot(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'data';
    }

    /** 功能包根目录：code/plugins。 */
    private static function pluginsRoot(): string
    {
        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'plugins';
    }
}
