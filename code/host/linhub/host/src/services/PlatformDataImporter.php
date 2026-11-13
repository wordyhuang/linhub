<?php

declare(strict_types=1);

namespace linhub\host\services;

use linhub\host\migration\DbGateway;
use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 服务数据随包导入编排器（数据随包导出与导入方案 V1.6 / 5.2）
 *
 * 安装/升级期由 IntegrationManager 在种子导入之后、能力接管之前调用：
 * 读包内 data/index.json 发现随包数据，逐服务调驱动写入（归属强制为包 short）。
 *
 * 卸载期由 admin 卸载端点调用 purgeOnRemove：按 manifest.data.services.<id>.remove_on_uninstall
 * （默认 true）调驱动清除该包数据；翻译词条清除已收敛至本出口（与 TranslationSeeder::purgePackage 同语义）。
 *
 * 容错原则：单服务失败不阻断整体（告警聚合），DB 不可用或无数据文件时静默跳过。
 */
final class PlatformDataImporter
{
    /**
     * 单请求内最近一次 importFromDir 的统计。
     * IntegrationManager::integrate 为 void，admin 安装/升级端点经此静态槽回传统计
     * （PHP 单请求生命周期内安全，仅用于响应展示，无跨请求语义）。
     *
     * @var array{services: list<array{service:string,imported:int,mode:string}>, warnings: list<string>}
     */
    public static array $lastStats = ['services' => [], 'warnings' => []];

    /**
     * 从包目录导入随包数据（index.json 驱动）。
     *
     * @param string     $packageDir 包根目录（安装落位目录/升级暂存目录）
     * @param string     $short      功能包短名
     * @param DbGateway|null $db     数据库网关；null = DB 不可用，静默跳过
     * @param array      $manifest   包 manifest（读 data.services.<id>.replace 决定导入模式）
     * @return array{services: list<array{service:string,imported:int,mode:string}>, warnings: list<string>}
     */
    public static function importFromDir(string $packageDir, string $short, ?DbGateway $db, array $manifest = []): array
    {
        self::$lastStats = ['services' => [], 'warnings' => []];
        $stats = ['services' => [], 'warnings' => []];
        if ($db === null || $short === '') {
            return $stats;
        }
        $indexFile = rtrim($packageDir, '/\\') . '/data/index.json';
        if (!is_file($indexFile)) {
            return $stats;
        }
        $index = json_decode((string) @file_get_contents($indexFile), true);
        if (!is_array($index)) {
            $stats['warnings'][] = 'data/index.json 解析失败，跳过服务数据导入';
            self::$lastStats = $stats;
            return $stats;
        }

        $store = new PlatformStore($db);
        foreach ((array) ($index['services'] ?? []) as $svc) {
            $svc = (array) $svc;
            $id = (string) ($svc['service'] ?? '');
            if ($id === '') {
                continue;
            }
            $cls = (string) (ServiceRegistry::hostList()[$id]['data_driver'] ?? '');
            if ($cls === '' || !class_exists($cls)) {
                $stats['warnings'][] = "服务 {$id} 无数据驱动或未注册，跳过导入";
                continue;
            }
            $payloadFile = rtrim($packageDir, '/\\') . '/' . ltrim((string) ($svc['file'] ?? ('data/' . $id . '.json')), '/\\');
            if (!is_file($payloadFile)) {
                $stats['warnings'][] = "缺少数据文件 {$svc['file']}，跳过服务 {$id} 导入";
                continue;
            }
            $payload = json_decode((string) @file_get_contents($payloadFile), true);
            $rows = is_array($payload) ? (array) ($payload['rows'] ?? []) : [];
            if ($rows === []) {
                continue;
            }
            $servicesPol = (array) ($manifest['data']['services'] ?? []);
            $pol = (array) ($servicesPol[$id] ?? []);
            $mode = (($pol['replace'] ?? false) === true) ? 'replace' : 'merge';
            $driver = new $cls($store);
            if (!$driver instanceof ServiceDataDriver) {
                $stats['warnings'][] = "服务 {$id} 驱动未实现 ServiceDataDriver，跳过导入";
                continue;
            }
            try {
                $n = $driver->import($short, $rows, $mode);
                $stats['services'][] = ['service' => $id, 'imported' => $n, 'mode' => $mode];
            } catch (\Throwable $e) {
                $stats['warnings'][] = "服务 {$id} 数据导入失败：" . $e->getMessage();
            }
        }
        self::$lastStats = $stats;
        return $stats;
    }

    /**
     * 卸载清除：遍历 manifest.data.services，按 remove_on_uninstall（默认 true）调驱动 purge。
     * 翻译词条清除由此统一出口承担（不再单独调 TranslationSeeder::purgePackage）。
     *
     * @return array<string,int> 服务 id => 清除行数（-1 = 该服务清除失败）
     */
    public static function purgeOnRemove(array $manifest, string $short, ?DbGateway $db): array
    {
        $out = [];
        if ($db === null || $short === '') {
            return $out;
        }
        $services = (array) ($manifest['data']['services'] ?? []);
        if ($services === []) {
            return $out; // 包未声明数据随包：无可清除项
        }
        $store = new PlatformStore($db);
        foreach ($services as $id => $pol) {
            $id = (string) $id;
            $pol = is_array($pol) ? $pol : [];
            if (($pol['remove_on_uninstall'] ?? true) !== true) {
                continue;
            }
            $cls = (string) (ServiceRegistry::hostList()[$id]['data_driver'] ?? '');
            if ($cls === '' || !class_exists($cls)) {
                continue;
            }
            $driver = new $cls($store);
            if (!$driver instanceof ServiceDataDriver) {
                continue;
            }
            try {
                $out[$id] = $driver->purge($short);
            } catch (\Throwable $e) {
                $out[$id] = -1; // 单服务失败不阻断卸载
            }
        }
        return $out;
    }
}
