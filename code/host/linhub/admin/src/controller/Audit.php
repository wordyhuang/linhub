<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\migration\MigrationAuditLog;
use linhub\host\migration\PdoGateway;
use think\facade\Config;
use think\Request;

/**
 * 日志与审计检索（G5）
 *
 * 统一检索页，三个 Tab：
 *  - 操作日志（GET /admin/audit/logs：AuditLog.search：keyword/packageId/level/from/to）
 *  - 管控轨迹（GET /admin/audit/trail：AuditTrail.query：action/operator/from/to）
 *  - 迁移审计（GET /admin/audit/migration：MigrationAuditLog.search：package/version/direction/operator，按 config/database.php 连 MySQL）
 *
 * 首页仅渲染空壳，数据由 wd-* 组件经 JSON 接口加载；options 提供筛选下拉选项。
 * G5.3 降级（O-5）：迁移审计依赖 MySQL，未配置库名或连接失败时返回 status='unavailable' 而非抛异常。
 */
class Audit extends AdminController
{
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('audit/index');
    }

    /** JSON：筛选下拉选项（级别 / 来源包 / 动作 / 执行人）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $bootstrap = $this->bootstrap;
        return $this->jsonOk([
            'levelOptions'   => ['info', 'warning', 'error', 'debug'],
            'logPackages'    => $this->distinct($bootstrap->auditLog()->all(), 'package_id'),
            'trailActions'   => $this->distinct($bootstrap->auditTrail()->all(), 'action'),
            'trailOperators' => $this->distinct($bootstrap->auditTrail()->all(), 'operator'),
        ]);
    }

    /** JSON：操作日志列表（workdesktop-ai 的 data-grid 数据源）。 */
    public function logs()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $list = $this->bootstrap->auditLog()->search(
            $this->emptyOrNull($req->get('kw')),
            $this->emptyOrNull($req->get('package')),
            $this->emptyOrNull($req->get('level')),
            $this->emptyOrNull($req->get('from')),
            $this->emptyOrNull($req->get('to')),
        );
        return $this->jsonOk(['list' => $list, 'total' => count($list)]);
    }

    /** JSON：管控轨迹列表（workdesktop-ai 的 data-grid 数据源）。 */
    public function trail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $list = $this->bootstrap->auditTrail()->query(
            $this->emptyOrNull($req->get('act')),
            $this->emptyOrNull($req->get('operator')),
            $this->emptyOrNull($req->get('from')),
            $this->emptyOrNull($req->get('to')),
        );
        return $this->jsonOk(['list' => $list, 'total' => count($list)]);
    }

    /** JSON：迁移审计记录（G5.3，失败降级为 unavailable，不阻断其余 Tab）。 */
    public function migration()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $state = $this->migrationAuditState($this->request);
        return $this->jsonOk($state);
    }

    /**
     * 迁移审计状态（G5.3）。按 config/database.php 的 mysql 连接尝试连通；
     * 未配置库名或连接失败一律降级为 unavailable，绝不抛出。
     *
     * @return array{status:string, items?:array, filter?:array, reason?:string}
     */
    private function migrationAuditState(Request $req): array
    {
        $filter = [];
        foreach (['package', 'version', 'direction', 'operator', 'migration'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        try {
            $cfg = (array) Config::get('database.connections.mysql', []);
            $dbname = (string) ($cfg['database'] ?? '');
            if ($dbname === '') {
                return ['status' => 'unavailable', 'reason' => '未配置迁移数据库（DB_NAME）', 'filter' => $filter];
            }
            if (!extension_loaded('pdo_mysql')) {
                return ['status' => 'unavailable', 'reason' => 'pdo_mysql 扩展未启用', 'filter' => $filter];
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                (string) ($cfg['hostname'] ?? '127.0.0.1'),
                (string) ($cfg['hostport'] ?? '3306'),
                $dbname,
                (string) ($cfg['charset'] ?? 'utf8mb4'),
            );
            $pdo = new \PDO($dsn, (string) ($cfg['username'] ?? 'root'), (string) ($cfg['password'] ?? ''));
            $db  = new PdoGateway($pdo);
            $log = new MigrationAuditLog($db);

            // 只读视图不做 ensure（避免浏览页产生建表副作用）；表不存在即空列表。
            if (!in_array(strtolower($log->table()), $db->tables(), true)) {
                return ['status' => 'ok', 'items' => [], 'filter' => $filter];
            }
            return ['status' => 'ok', 'items' => $log->search($filter), 'filter' => $filter];
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'reason' => $e->getMessage(), 'filter' => $filter];
        }
    }

    /** 空串归一化为 null（AuditLog.search / AuditTrail.query 常用 null 表示不限）。 */
    private function emptyOrNull(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** 提取某一字段的去重非空值列表（用于筛选下拉）。 */
    private function distinct(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$field] ?? ''));
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        $keys = array_keys($out);
        sort($keys);
        return $keys;
    }
}