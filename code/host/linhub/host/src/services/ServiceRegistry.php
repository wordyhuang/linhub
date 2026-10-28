<?php

declare(strict_types=1);

namespace linhub\host\services;

use linhub\host\manifest\VersionConstraint;

/**
 * 宿主服务注册表（T1.6 / M7-M12 / 协议规范四之一）
 *
 * 统一登记 service_id => [类, 版本, source]。source 取值 host|package。
 *  - host 源：平台注册的宿主服务，功能包只能消费，禁止写入（安全红线）。
 *  - package 源：功能包经校验成功后可注册能力为宿主服务（N3/M）。
 *
 * host 服务事实来源为 services.php 清单（services.php），不再硬编码。
 * 这是功能包注入与解析宿主服务版本的唯一事实来源。
 */
final class ServiceRegistry
{
    /** id => ['class' => ..., 'version' => ..., 'source' => ...] */
    private static array $registry = [];

    /** id => ['contract' => ..., 'impl' => ..., 'version' => ..., 'source' => ..., 'desc' => ...] */
    private static ?array $manifest = null;

    private function __construct()
    {
    }

    /**
     * 读取服务清单（services.php）。懒加载并缓存。
     *
     * 返回结构见 services.php 注释；供 bootstrap 注入与 Admin 治理页 / ServiceInjector 复用。
     */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            self::$manifest = require __DIR__ . '/services.php';
        }
        return self::$manifest;
    }

    /** host 服务清单（list 键）。 */
    public static function hostList(): array
    {
        return (array) (self::manifest()['list'] ?? []);
    }

    /** 读取单个 host 服务的精确版本号；不存在返回 null。安装期清单事实源，不依赖 bootstrap。 */
    public static function hostVersion(string $id): ?string
    {
        $entry = self::hostList()[$id] ?? null;
        return $entry === null ? null : (string) ($entry['version'] ?? '');
    }

    /**
     * 收集已启用功能包的 provides 能力（事实源：plugin-state.json + 各包 manifest）。
     * 支持路径注入以便测试隔离；不注入时读生产默认路径。
     * 附带服务说明：desc（简介）与 params（参数说明，规范化为 name/desc 列表）。
     *
     * @return array<string, array{class:string,version:string,source:string,desc:string,params:list<array{name:string,desc:string}>}>
     */
    public static function packageProvides(?string $stateFile = null, ?string $pluginsRoot = null): array
    {
        $stateFile = $stateFile ?? (dirname(__DIR__, 4) . '/data/plugin-state.json');
        $pluginsRoot = $pluginsRoot ?? (dirname(__DIR__, 5) . '/plugins');
        if (!is_file($stateFile)) {
            return [];
        }
        $raw = @file_get_contents($stateFile);
        $records = $raw === false ? [] : (json_decode($raw, true) ?: []);
        $out = [];
        foreach ((array) $records as $rec) {
            if ((string) ($rec['state'] ?? '') !== 'enabled') {
                continue;
            }
            $short = (string) ($rec['short'] ?? '');
            if ($short === '') {
                continue;
            }
            $manifestFile = $pluginsRoot . '/' . $short . '/manifest.php';
            if (!is_file($manifestFile)) {
                continue;
            }
            $manifest = @include $manifestFile;
            if (!is_array($manifest)) {
                continue;
            }
            foreach ((array) ($manifest['services']['provides'] ?? []) as $p) {
                $p = (array) $p;
                $id = (string) ($p['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[$id] = [
                    'class'   => (string) ($p['class'] ?? ''),
                    'version' => (string) ($p['version'] ?? ''),
                    'source'  => 'package',
                    'desc'    => (string) ($p['desc'] ?? ''),
                    'params'  => self::normalizeParams($p['params'] ?? null),
                ];
            }
        }
        return $out;
    }

    /**
     * 规范化 provides.params 为展示友好的列表。
     * 字符串 → 整段说明（name 为空）；数组 → 方法名/条目 => 说明；其余 → 空列表。
     *
     * @return list<array{name:string,desc:string}>
     */
    private static function normalizeParams(mixed $params): array
    {
        if (is_string($params)) {
            return $params === '' ? [] : [['name' => '', 'desc' => $params]];
        }
        if (!is_array($params)) {
            return [];
        }
        $out = [];
        foreach ($params as $k => $v) {
            $out[] = ['name' => (string) $k, 'desc' => (string) $v];
        }
        return $out;
    }

    /** 读取服务版本：host 源优先，其次 package 源（provides）。不存在返回 null。 */
    public static function versionOf(string $id): ?string
    {
        $v = self::hostVersion($id);
        if ($v !== null) {
            return $v;
        }
        foreach (self::packageProvides() as $sid => $entry) {
            if ($sid === $id) {
                return (string) ($entry['version'] ?? '');
            }
        }
        return null;
    }

    /** 返回某服务的注册信息；未注册返回 null。 */
    public static function get(string $id): ?array
    {
        return self::$registry[$id] ?? null;
    }

    /** 返回全部注册服务。 */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * 注入宿主服务（构造时调用）。功能包不得调用本方法。
     *
     * 遍历 services.php 清单的 list 键；class 取契约接口类，source 恒为 host。
     */
    public static function bootstrap(): void
    {
        foreach (self::hostList() as $id => $entry) {
            self::$registry[$id] = [
                'class'   => (string) ($entry['contract'] ?? ''),
                'version' => (string) ($entry['version'] ?? '0.0.0'),
                'source'  => (string) ($entry['source'] ?? 'host'),
            ];
        }
    }

    /**
     * 功能包注册能力为宿主服务（N3/M）。
     *
     * @throws \RuntimeException 试图写入 host 源或重复注册时抛出。
     */
    public static function registerPackage(string $id, string $class, string $version = '^1.0'): void
    {
        $existing = self::$registry[$id] ?? null;
        if ($existing !== null && $existing['source'] === 'host') {
            throw new \RuntimeException("禁止功能包写入 host 源服务：{$id}");
        }
        if ($existing !== null) {
            throw new \RuntimeException("服务已注册，无法覆盖：{$id}");
        }
        self::$registry[$id] = [
            'class'   => $class,
            'version' => $version,
            'source'  => 'package',
        ];
    }

    /**
     * 按版本约束解析服务：校验注册服务版本满足约束（M10），满足返回注册信息。
     *
     * @param string $constraint 形如 '^1.0' / '>=0.1.0'
     * @throws \RuntimeException 服务不存在或版本不满足约束
     */
    public static function resolve(string $id, string $constraint = '*'): array
    {
        $entry = self::$registry[$id] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("服务未注册：{$id}");
        }
        // 精确语义化版本约束判定（复用 VersionConstraint，支持 ^/~/>=/< /逗号 AND 区间）
        if ($constraint !== '*' && $constraint !== ''
            && !VersionConstraint::matches((string) $entry['version'], $constraint)) {
            throw new \RuntimeException("服务 {$id} 版本不满足约束：{$constraint}（当前 {$entry['version']}）");
        }
        return $entry;
    }
}