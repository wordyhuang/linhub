<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\metrics\ServiceCallStatStore;
use linhub\host\migration\PdoGateway;
use linhub\host\services\ServiceRegistry;
use think\facade\Env;

/**
 * 服务治理看板（需求 E / G3）—— admin 应用，只读
 *
 *  - GET  /admin/services/dashboard   治理页空壳（前端拉 overview 渲染）
 *  - GET  /admin/services/overview    综合 JSON：服务清单 + 能力清单 + 升级记录
 *
 * 数据来源：
 *  - 服务清单：services/services.php（ServiceRegistry::hostList + changelog）
 *  - 运行时事实：ServiceRegistry::all()（含功能包注册的来源 service）
 *  - 持久化镜像：lg_mgt_service / lg_mgt_capability（LinserviceStore 写入的系统库表）
 *
 * 只读约束（G3）：本期仅展示画像，服务增删改走 services.php + Git，页面不提供写接口。
 * 降级：DB 不可用时 services 仍可从清单+内存展示，capabilities 返回诊断信息，不白屏。
 */
class ServiceGovernance extends AdminController
{
    /** 建立 MySQL 网关（按 config/database.php 的 env）。 */
    private function connect(): array
    {
        $driver = (string) Env::get('DB_TYPE', 'mysql');
        $host   = (string) Env::get('DB_HOST', '127.0.0.1');
        $port   = (string) Env::get('DB_PORT', '3306');
        $db     = (string) Env::get('DB_NAME', '');
        $user   = (string) Env::get('DB_USER', 'root');
        $pass   = (string) Env::get('DB_PASS', '');
        $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "{$driver}:host={$host};port={$port};dbname={$db};charset={$charset}";
        try {
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return ['ok' => true, 'gateway' => new PdoGateway($pdo)];
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'diagnostic' => [
                    'driver' => $driver,
                    'host'   => $host . ':' . $port,
                    'db'     => $db,
                    'error'  => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * 被依赖视图（V7.29）：聚合已装功能包 manifest.services.requires，
     * 返回 host 服务 id => [['pkg' => 包名/短名, 'constraint' => 版本约束], ...]。
     * 数据取自 data/plugin-state.json + 各包落地 manifest，不依赖 bootstrap。
     *
     * @return array<string, list<array{pkg:string, constraint:string}>>
     */
    private function consumers(): array
    {
        $stateFile = dirname(__DIR__, 4) . '/data/plugin-state.json';
        $raw = is_file($stateFile) ? @file_get_contents($stateFile) : false;
        $records = $raw === false ? [] : (json_decode((string) $raw, true) ?: []);

        $map = [];
        foreach ((array) $records as $rec) {
            $state = (string) ($rec['state'] ?? '');
            if (!in_array($state, ['enabled', 'disabled'], true)) {
                continue;
            }
            // source_dir 正反斜杠混合，归一为当前 OS 目录分隔
            $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($rec['source_dir'] ?? ''));
            if ($dir === '' || !is_file($dir . '/manifest.php')) {
                continue;
            }
            $manifest = @include $dir . '/manifest.php';
            if (!is_array($manifest)) {
                continue;
            }
            $pkg = (string) ($rec['short'] ?? ($manifest['short'] ?? ''));
            foreach ((array) ($manifest['services']['requires'] ?? []) as $key => $req) {
                if (is_string($key)) {
                    $id = $key;
                    $constraint = (string) $req;
                } elseif (is_string($req)) {
                    $id = $req;
                    $constraint = '*';
                } else {
                    $entry = (array) $req;
                    $id = (string) key($entry);
                    $constraint = (string) reset($entry);
                }
                if ($id === '') {
                    continue;
                }
                $map[$id][] = ['pkg' => $pkg, 'constraint' => $constraint];
            }
        }
        return $map;
    }

    /** 综合治理数据。 */
    private function payload(): array
    {
        ServiceRegistry::bootstrap();

        // 被依赖聚合（host 服务 id => 功能包消费者列表）
        $consumers = $this->consumers();

        // 服务清单（来源 services.php，含 desc）+ 运行时注册（含 package 源）
        // package 源服务的说明取自各包 manifest.services.provides 的 desc/params（简介与参数说明）
        $list = ServiceRegistry::hostList();
        $pkgProvides = ServiceRegistry::packageProvides();
        $runtime = ServiceRegistry::all();
        $services = [];
        foreach ($runtime as $id => $e) {
            $isPkg = ($e['source'] ?? '') === 'package';
            $pkg   = $isPkg ? ($pkgProvides[(string) $id] ?? []) : [];
            $services[] = [
                'id'      => (string) $id,
                'class'   => (string) ($e['class'] ?? ''),
                'version' => (string) ($e['version'] ?? ''),
                'source'  => (string) ($e['source'] ?? ''),
                'desc'    => $isPkg
                    ? ((string) ($pkg['desc'] ?? '') !== '' ? (string) $pkg['desc'] : '功能包托管服务')
                    : (string) ($list[$id]['desc'] ?? ''),
                'params'  => (array) ($pkg['params'] ?? []),
                'consumers' => $consumers[(string) $id] ?? [],
            ];
        }
        // 按 id 排序，稳定展示
        usort($services, fn ($a, $b) => strcmp($a['id'], $b['id']));

        // 升级记录（services.php changelog）
        $manifest = ServiceRegistry::manifest();
        $changelog = [];
        foreach ((array) ($manifest['changelog'] ?? []) as $key => $row) {
            $changelog[] = [
                'key'   => (string) $key,
                'date'  => (string) ($row[0] ?? ''),
                'note'  => (string) ($row[1] ?? ''),
            ];
        }
        usort($changelog, fn ($a, $b) => strcmp($b['date'], $a['date']));

        // 持久化能力镜像
        $conn = $this->connect();
        $capabilities = [];
        $diagnostic = null;
        if ($conn['ok']) {
            try {
                $rows = $conn['gateway']->query(
                    'SELECT pkg_key, cap_type, payload, updated_at FROM lg_mgt_capability ORDER BY pkg_key, cap_type'
                );
                foreach ($rows as $r) {
                    $json = (string) ($r['payload'] ?? '');
                    $decoded = json_decode($json, true);
                    $capabilities[] = [
                        'pkgKey'   => (string) ($r['pkg_key'] ?? ''),
                        'capType'  => (string) ($r['cap_type'] ?? ''),
                        'payload'  => is_array($decoded) ? $decoded : ['raw' => $json],
                        'updatedAt' => (string) ($r['updated_at'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                $diagnostic = ['driver' => Env::get('DB_TYPE', 'mysql'), 'host' => Env::get('DB_HOST', '') . ':' . Env::get('DB_PORT', ''), 'db' => Env::get('DB_NAME', ''), 'error' => $e->getMessage()];
            }
        } else {
            $diagnostic = $conn['diagnostic'];
        }

        return [
            'services'     => $services,
            'calls'        => ServiceCallStatStore::all(),
            'capabilities' => $capabilities,
            'changelog'    => $changelog,
            'diagnostic'   => $diagnostic,
        ];
    }

    /** 治理页（空壳）。 */
    public function dashboard()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('services/dashboard');
    }

    /** 综合治理 JSON。 */
    public function overview()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk($this->payload());
    }
}