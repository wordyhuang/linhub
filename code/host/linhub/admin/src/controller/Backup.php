<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\lifecycle\BackupManager;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\security\HighRiskProtector;
use think\facade\Env;
use think\Request;

/**
 * 数据归档备份 GUI（G6 / T5.8）—— admin 应用
 *
 *  - GET  /admin/backup/index       归档总览页（空壳，数据由 wd-* 组件经 JSON 接口加载）
 *  - GET  /admin/backup/rows        总览数据：policy + RBAC 快照 + 包源码备份
 *  - POST /admin/backup/savePolicy  写归档策略（enabled/retain/dir），记审计
 *  - POST /admin/backup/run         立即归档（ArchiveBackup::archive），返回快照 id，记审计
 *  - POST /admin/backup/restore     还原（高危：前端输入快照 id 强确认 + 后端确认语义），记审计
 *
 * 登录 + 超管双门禁统一走 AdminController::gate() / gateForJson()。
 */
class Backup extends AdminController
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

    /** 归档总览页：渲染空壳，数据由 wd-* 组件经 /admin/backup/rows 加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('backup/index');
    }

    /**
     * JSON：总览数据（workdesktop-ai 数据源）。
     * 上半屏 RBAC 快照 + 下半屏各已装包源码备份 + 归档策略。
     */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk([
            'policy'         => $this->bootstrap->archiveBackup()->policy(),
            'snapshots'      => $this->snapshots(),
            'packageBackups' => $this->packageBackups(),
        ]);
    }

    /** JSON：写归档策略（enabled/retain/dir），保留份数须为正整数，否则拒绝，记审计。 */
    public function savePolicy()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;
        $req      = $this->request;

        $enabled = in_array((string) $req->post('enabled', ''), ['1', 'true', 'on', 'yes'], true);
        $retain  = (int) $req->post('retain', 0);
        $dir     = trim((string) $req->post('dir', ''));

        $err = $this->validatePolicy($retain, $dir);
        if ($err !== null) {
            return $this->jsonFail($err, 422);
        }

        try {
            $this->bootstrap->archiveBackup()->policy([
                'enabled' => $enabled,
                'retain'  => $retain,
                'dir'     => $dir,
            ]);
            $this->bootstrap->auditTrail()->log('backup.policy', 'policy', (string) $identity->name, [
                'enabled' => $enabled,
                'retain'  => $retain,
                'dir'     => $dir,
            ]);
            return $this->jsonOk(null, '归档策略已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** JSON：立即归档 RBAC 数据，返回新快照，记审计。 */
    public function run()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        try {
            $record = $this->bootstrap->archiveBackup()->archive();
            $sid    = (string) ($record['id'] ?? '');
            $this->bootstrap->auditTrail()->log('backup.run', $sid, (string) $identity->name, []);
            return $this->jsonOk(['id' => $sid], '已归档为快照：' . $sid);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * JSON：还原（高危）。前端要求输入快照 id 强确认，后端校验 confirm === snapshotId
     * 才放行（对齐 HighRiskProtector 显式确认语义），成功后返回 ok，记审计。
     */
    public function restore()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $sid     = (string) $this->request->post('snapshotId', '');
        $confirm = (string) $this->request->post('confirm', '');
        if ($sid === '') {
            return $this->jsonFail('缺少快照 id', 422);
        }

        try {
            (new HighRiskProtector($sid))->allowWithConfirm($confirm);
        } catch (\Throwable $e) {
            return $this->jsonFail('还原未确认：请完整输入快照 id 以确认高危还原', 422);
        }

        try {
            $ok = $this->bootstrap->archiveBackup()->restore($sid);
            if (!$ok) {
                throw new \RuntimeException('快照不存在或快照文件缺失');
            }
            $this->bootstrap->auditTrail()->log('backup.restore', $sid, (string) $identity->name, []);
            return $this->jsonOk(null, "已从快照 {$sid} 还原用户数据");
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** RBAC 归档快照列表（仅投影前端所需字段）。 */
    private function snapshots(): array
    {
        $out = [];
        foreach ($this->bootstrap->archiveBackup()->list() as $s) {
            $out[] = [
                'id'         => (string) ($s['id'] ?? ''),
                'file'       => (string) ($s['file'] ?? ''),
                'created_at' => (string) ($s['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** 包源码备份（按已装包分组）。 */
    private function packageBackups(): array
    {
        $out = [];
        $bm  = new BackupManager($this->backupRoot());
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            $backups = [];
            foreach ($bm->list((string) $name) as $b) {
                $backups[] = [
                    'path' => (string) ($b['path'] ?? ''),
                    'tag'  => (string) ($b['tag'] ?? ''),
                    'name' => basename((string) ($b['path'] ?? '')),
                ];
            }
            $out[] = [
                'name'    => (string) ($rec['name'] ?? $name),
                'backups' => $backups,
            ];
        }
        return $out;
    }

    /** 校验归档策略合法值：保留份数须为正整数，目录非空；合法返回 null，否则返回错误文案。 */
    private function validatePolicy(int $retain, string $dir): ?string
    {
        if ($retain < 1) {
            return '保留份数必须为正整数';
        }
        if ($dir === '') {
            return '归档目录不能为空';
        }
        return null;
    }
}