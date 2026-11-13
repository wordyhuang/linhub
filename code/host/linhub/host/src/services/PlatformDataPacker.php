<?php

declare(strict_types=1);

namespace linhub\host\services;

use linhub\host\migration\DbGateway;
use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 服务数据随包导出编排器（数据随包导出与导入方案 V1.6 / 5.2）
 *
 * 打包期由 admin 打包端点调用：遍历 services.php 声明 data_driver 的服务，
 * 按 manifest.data.services.<id> 逐服务策略过滤，调驱动导出并生成：
 *  - data/<id>.json ：单服务数据（自描述信封：service/package/scope/range/exported_at/rows）
 *  - data/index.json：汇总清单（版本/包名/各服务行数/截断标记/跳过原因），供安装侧发现与审计
 *
 * 策略口径：
 *  - manifest 未声明 data 段，或 data.enable=false：不产出任何数据文件
 *  - 单服务策略缺省：enable=true、scope=seed（只带基线，安全默认）
 *  - scope 不在驱动 capabilities.scopes 内：跳过并记录原因（驱动不支持的范围→打包期告警）
 *  - 行数超 EXPORT_ROW_LIMIT：截断（truncated=true），包体积可控
 */
final class PlatformDataPacker
{
    /** 单服务导出行数上限（超出截断）。 */
    public const EXPORT_ROW_LIMIT = 50000;

    /**
     * 生成随包数据文件集。
     *
     * @param string    $short    功能包短名
     * @param array     $manifest 包 manifest（读 data 段）
     * @param DbGateway $db       数据库网关（打包端点须先建库连接）
     * @return array<string,string> 相对包根路径 => 文件内容（UTF-8 JSON）；空数组 = 本包无数据随包
     * @throws \RuntimeException 驱动导出失败时抛出（打包应中止，避免产出数据缺失的误导性包）
     */
    public static function build(string $short, array $manifest, DbGateway $db): array
    {
        $data = $manifest['data'] ?? null;
        if (!is_array($data) || (($data['enable'] ?? true) === false)) {
            return [];
        }
        $perSvc = (array) ($data['services'] ?? []);
        $store = new PlatformStore($db);
        $services = [];
        $skipped = [];
        $files = [];

        foreach (ServiceRegistry::hostList() as $id => $entry) {
            $cls = (string) ($entry['data_driver'] ?? '');
            if ($cls === '' || !class_exists($cls)) {
                continue; // 未声明驱动：白名单机制，天然不参与
            }
            $pol = (array) ($perSvc[$id] ?? []);
            if (($pol['enable'] ?? true) === false) {
                $skipped[] = ['service' => $id, 'reason' => 'manifest.data.services.' . $id . '.enable = false'];
                continue;
            }
            $driver = new $cls($store);
            if (!$driver instanceof ServiceDataDriver) {
                $skipped[] = ['service' => $id, 'reason' => '驱动未实现 ServiceDataDriver'];
                continue;
            }
            $cap = $driver->capabilities();
            // 托管运行态数据的服务须在 manifest.data.services 显式声明才参与导出（方案 5.3/6.1 默认规则）
            if (!array_key_exists($id, $perSvc) && (bool) ($cap['has_runtime'] ?? false)) {
                $skipped[] = ['service' => $id, 'reason' => '运行态服务未在 manifest.data.services 显式声明，默认不参与'];
                continue;
            }
            $scopes = (array) ($cap['scopes'] ?? []);
            $scope = (string) ($pol['scope'] ?? 'seed');
            if (!in_array($scope, $scopes, true)) {
                $skipped[] = [
                    'service' => $id,
                    'reason'  => "驱动不支持 scope={$scope}（支持：" . implode('/', $scopes) . '）',
                ];
                continue;
            }
            $range = null;
            if ($scope === 'range') {
                $cfg = (array) ($pol['range'] ?? []);
                $range = [
                    'from' => (string) ($cfg['from'] ?? date('Y-m-d', strtotime('-90 days'))),
                    'to'   => (string) ($cfg['to'] ?? date('Y-m-d')),
                ];
            }
            try {
                $res = $driver->export($short, $scope, $range, self::EXPORT_ROW_LIMIT);
            } catch (\Throwable $e) {
                throw new \RuntimeException("服务 {$id} 数据导出失败：" . $e->getMessage(), 0, $e);
            }
            $rows = (array) ($res['rows'] ?? []);
            if ($rows === []) {
                $skipped[] = ['service' => $id, 'reason' => '无数据（scope=' . $scope . '）'];
                continue;
            }
            $file = 'data/' . $id . '.json';
            $files[$file] = self::json([
                'service'     => $id,
                'package'     => $short,
                'scope'       => $scope,
                'range'       => $range,
                'exported_at' => date('c'),
                'rows'        => $rows,
            ]);
            $services[] = [
                'service'   => $id,
                'file'      => $file,
                'scope'     => $scope,
                'rows'      => count($rows),
                'truncated' => (bool) ($res['truncated'] ?? false),
            ];
        }

        if ($services === [] && $skipped === []) {
            return []; // 无任何参与服务：不生成空 index
        }
        $files['data/index.json'] = self::json([
            'version'      => 1,
            'package'      => $short,
            'generated_at' => date('c'),
            'services'     => $services,
            'skipped'      => $skipped,
        ]);
        return $files;
    }

    private static function json(array $d): string
    {
        return (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
