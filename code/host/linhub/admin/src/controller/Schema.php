<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\lifecycle\BackupManager;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\migration\BackupEngine;
use linhub\host\migration\PdoGateway;
use linhub\host\migration\SchemaDiff;
use linhub\host\migration\SchemaSnapshot;
use think\facade\Env;

/**
 * Schema 差异看板（G8 / T3.4）—— admin 应用
 *
 *  - GET  /admin/schema/diff      差异查看页空壳（前端拉取 options/report 渲染）
 *  - GET  /admin/schema/options   选项概览 JSON：已装包 + 各包备份概览 + 当前库表数与连接诊断
 *  - GET  /admin/schema/report    差异报告 JSON：当前库结构 vs 选定期目标发版快照（备份内 schema.json）
 *
 * 数据源：
 *  - 当前结构：按 config/database.php 的 env 建立 PdoGateway 后取 SchemaSnapshot::fromGateway()；
 *  - 目标结构：选中功能包备份内写入的 schema.json（BackupEngine::readSchema），无备份则提示先安装/升级产生快照；
 *  - 差异：SchemaDiff::diff(当前, 目标) 渲染为表 / 列 / 索引三类增删改清单。
 *
 * 降级（G8.2）：MySQL 连接失败时展示诊断信息（驱动 + 错误原文），不抛白屏。
 */
class Schema extends AdminController
{
    /** 宿主根（code/host）。 */
    private function baseDir(): string
    {
        return dirname(__DIR__, 4);
    }

    /** 功能包状态记录文件绝对路径。 */
    private function stateFile(): string
    {
        return $this->envPath('PACKAGE_STATE_FILE', $this->baseDir() . '/data/plugin-state.json');
    }

    /** 包源码备份根目录。 */
    private function backupRoot(): string
    {
        return $this->envPath('PACKAGE_BACKUP_ROOT', $this->baseDir() . '/data/plugin-backups');
    }

    /** 读取 .env 配置项（带默认值回退）；相对路径基于宿主根解析。 */
    private function envPath(string $key, string $fallback): string
    {
        $val = Env::get($key);
        if ($val === null || (string) $val === '') {
            return $fallback;
        }
        $val = trim((string) $val);
        if (!$this->isAbsolutePath($val)) {
            $val = $this->baseDir() . '/' . ltrim(str_replace('\\', '/', $val), '/');
        }
        return str_replace('\\', '/', $val);
    }

    /** 判断路径是否为绝对路径（Windows 盘符或 Unix 根开头的正斜杠/反斜杠）。 */
    private function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[/\\\\]|/|\\\\)#', $path) === 1;
    }

    /**
     * 建立 MySQL 网关（按 config/database.php 的 env）。
     *
     * @return array{ok:bool, gateway?:PdoGateway, diagnostic?:array<string,mixed>}
     */
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

    /** 已装功能包 + 各包备份概览（是否含 schema 快照）。 */
    private function packageOverview(): array
    {
        $bm = new BackupManager($this->backupRoot());
        $eng = new BackupEngine($this->backupRoot());
        $out = [];
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            $backups  = $bm->list((string) $name);
            $snapback = $this->schemaBackups($eng, $backups);
            $out[] = [
                'name'        => (string) $name,
                'short'       => (string) ($rec['short'] ?? $name),
                'version'     => (string) ($rec['version'] ?? ''),
                'backupCount' => count($backups),
                'snapshotCount'=> count($snapback),
                'latestPath'  => $snapback !== [] ? end($snapback)['path'] : null,
            ];
        }
        return $out;
    }

    /** 从备份列表中筛出含 schema.json 的备份（按时间升序），返回 [{path,name,tag}]。 */
    private function schemaBackups(BackupEngine $eng, array $backups): array
    {
        $out = [];
        foreach ($backups as $b) {
            $path = (string) ($b['path'] ?? '');
            if ($path === '' || $eng->readSchema($path) === null) {
                continue;
            }
            $out[] = [
                'path' => $path,
                'name' => basename($path),
                'tag'  => (string) ($b['tag'] ?? ''),
            ];
        }
        return $out;
    }

    /** 将 diff 平面清单按 kind 分组（table / column / index），组内再按增删改分三类。 */
    private function groupDiff(array $diff): array
    {
        $grouped = [
            'table'  => ['kind' => 'table',  'title' => '表',   'added' => [], 'removed' => [], 'changed' => []],
            'column' => ['kind' => 'column', 'title' => '列',   'added' => [], 'removed' => [], 'changed' => []],
            'index'  => ['kind' => 'index',  'title' => '索引', 'added' => [], 'removed' => [], 'changed' => []],
        ];
        foreach ($diff as $row) {
            $kind = (string) ($row['kind'] ?? '');
            if (!isset($grouped[$kind])) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $bucket = match ($type) {
                'added'   => 'added',
                'removed' => 'removed',
                'changed' => 'changed',
                default   => 'changed',
            };
            $grouped[$kind][$bucket][] = [
                'table'  => (string) ($row['table'] ?? ''),
                'name'   => (string) ($row['name'] ?? ''),
                'detail' => (string) ($row['detail'] ?? ''),
            ];
        }
        return array_values($grouped);
    }

    /** 主要差异查看页（空壳）。 */
    public function diff()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('schema/diff');
    }

    /**
     * 选项概览 JSON：已装包 + 各包备份概览 + 当前库表数与连接诊断。
     *
     * 返回 {packages, current, diagnostic}。
     */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $packages = $this->packageOverview();
        $conn     = $this->connect();
        $diag     = null;
        $current  = null;
        if ($conn['ok']) {
            try {
                $current = SchemaSnapshot::fromGateway($conn['gateway']);
            } catch (\Throwable $e) {
                $diag = ['driver' => Env::get('DB_TYPE', 'mysql'), 'host' => Env::get('DB_HOST', '') . ':' . Env::get('DB_PORT', ''), 'db' => Env::get('DB_NAME', ''), 'error' => $e->getMessage()];
            }
        } else {
            $diag = $conn['diagnostic'];
        }

        return $this->jsonOk([
            'packages'   => $packages,
            'current'    => $current === null ? null : [
                'tableCount' => count($current['tables'] ?? []),
                'names'      => array_keys($current['tables'] ?? []),
            ],
            'diagnostic' => $diag,
        ]);
    }

    /**
     * 差异报告 JSON：目标发版快照 + 与当前库的 diff 分组。
     *
     * 参数：package / version。返回 {current, diagnostic, target, report}。
     */
    public function report()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $package = trim((string) $this->request->get('package', ''));
        $version = trim((string) $this->request->get('version', ''));
        if ($package === '') {
            return $this->jsonFail('缺少比对功能包', 422);
        }

        // 数据库连接（失败则降级，不白屏）
        $conn    = $this->connect();
        $diag    = null;
        $current = null;
        if ($conn['ok']) {
            try {
                $current = SchemaSnapshot::fromGateway($conn['gateway']);
            } catch (\Throwable $e) {
                $diag = ['driver' => Env::get('DB_TYPE', 'mysql'), 'host' => Env::get('DB_HOST', '') . ':' . Env::get('DB_PORT', ''), 'db' => Env::get('DB_NAME', ''), 'error' => $e->getMessage()];
            }
        } else {
            $diag = $conn['diagnostic'];
        }

        if ($current === null) {
            return $this->jsonOk([
                'current' => null,
                'diagnostic' => $diag,
                'target'  => null,
                'report'  => null,
            ]);
        }

        // 目标发版快照 + 差异
        $eng = new BackupEngine($this->backupRoot());
        $bm  = new BackupManager($this->backupRoot());
        $snapbacks = $this->schemaBackups($eng, $bm->list($package));
        if ($snapbacks === []) {
            return $this->jsonOk([
                'current' => ['tableCount' => count($current['tables'] ?? []), 'names' => array_keys($current['tables'] ?? [])],
                'diagnostic' => $diag,
                'target'  => null,
                'report'  => ['noSnapshot' => true, 'message' => '「' . $package . '」尚无 schema 发版快照，请先执行安装 / 升级产生备份快照后再比对。'],
            ]);
        }

        // 选参：version 匹配备注名则用该备份，否则取最新（含 schema 的最后一个）
        $targetPath = $snapbacks[count($snapbacks) - 1]['path'];
        if ($version !== '') {
            foreach ($snapbacks as $sb) {
                if ($sb['name'] === $version) {
                    $targetPath = $sb['path'];
                    break;
                }
            }
        }
        $target = $eng->readSchema($targetPath);
        if (!is_array($target)) {
            return $this->jsonOk([
                'current' => ['tableCount' => count($current['tables'] ?? []), 'names' => array_keys($current['tables'] ?? [])],
                'diagnostic' => $diag,
                'target'  => null,
                'report'  => ['noSnapshot' => true, 'message' => 'readSchema 失败，备份内缺少合法 schema.json。'],
            ]);
        }

        $targetMeta = [
            'version'    => (string) ($target['version'] ?? ''),
            'tableCount' => count($target['tables'] ?? []),
            'backupName' => basename($targetPath),
        ];
        return $this->jsonOk([
            'current' => ['tableCount' => count($current['tables'] ?? []), 'names' => array_keys($current['tables'] ?? [])],
            'diagnostic' => $diag,
            'target'  => $targetMeta,
            'report'  => [
                'noSnapshot'     => false,
                'groups'         => $this->groupDiff(SchemaDiff::diff($current, $target)),
                'fromTableCount' => count($current['tables'] ?? []),
                'toTableCount'   => count($target['tables'] ?? []),
            ],
        ]);
    }
}