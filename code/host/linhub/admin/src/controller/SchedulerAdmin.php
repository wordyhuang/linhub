<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\scheduler\SchedulerHeartbeat;
use linhub\host\services\scheduler\SchedulerInstaller;
use linhub\host\services\scheduler\SchedulerProbe;
use linhub\host\services\scheduler\SchedulerRunner;
use linhub\host\services\scheduler\SchedulerService;
use linhub\host\services\scheduler\SchedulerSettings;
use linhub\host\services\scheduler\TaskHandlerLoader;
use linhub\host\services\support\PlatformStore;
use think\facade\Env;

/**
 * 异步任务托管服务（Scheduler）超管页 —— admin 应用，仅超管
 *
 *  - GET  /admin/scheduler_admin/index             页面空壳（4 页签：概览 / 周期定义 / 执行实例 / 设置）
 *  - GET  /admin/scheduler_admin/overview          总览：状态计数、心跳、档位、最近 tick
 *  - GET  /admin/scheduler_admin/defs              周期定义分页检索
 *  - GET  /admin/scheduler_admin/runs              执行实例分页检索
 *  - POST /admin/scheduler_admin/pauseDef          暂停周期定义
 *  - POST /admin/scheduler_admin/resumeDef         恢复周期定义（重算 next_run）
 *  - POST /admin/scheduler_admin/deleteDef         删除周期定义
 *  - POST /admin/scheduler_admin/triggerNow        立即触发一次周期定义
 *  - POST /admin/scheduler_admin/cancelRun         取消执行实例（queued 直删 / running 打标记）
 *  - GET  /admin/scheduler_admin/settings          42 项配置 + 配置表结构
 *  - POST /admin/scheduler_admin/settingsSave      批量保存配置（整体校验，非法即拒）
 *  - POST /admin/scheduler_admin/settingsReset     恢复默认配置
 *  - GET  /admin/scheduler_admin/probe             环境自检（fresh=1 跳过缓存）
 *  - GET  /admin/scheduler_admin/installCommands   常驻心跳部署命令文本（只读，不执行）
 *  - POST /admin/scheduler_admin/tickNow           手动执行一次 tick
 *
 * 管理端跨命名空间操作直接读写 PlatformStore，不走 SchedulerService（后者首参绑定单包身份）；
 * 全部写操作 audit 留痕，失败不阻断主流程。
 */
class SchedulerAdmin extends AdminController
{
    private function store(): PlatformStore
    {
        return new PlatformStore();
    }

    private function schedulerSettings(): SchedulerSettings
    {
        return new SchedulerSettings();
    }

    private function service(): SchedulerService
    {
        return new SchedulerService($this->store(), $this->schedulerSettings(), new TaskHandlerLoader());
    }

    /** 页面空壳，数据由前端经 JSON 接口加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('scheduler_admin/index');
    }

    /** JSON：总览（状态计数 / 心跳 / 档位 / 最近 tick / 平台规模）。 */
    public function overview()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $store = $this->store();
            $service = $this->service();
            $states = $store->countRunsByState();
            $defs = $store->listDefs([], 1, 1);
            $heartbeat = (new SchedulerHeartbeat($service))->status();
            $lastTick = (new SchedulerHeartbeat($service))->lastTick();
            $metrics = $service->metrics();
        } catch (\Throwable $e) {
            return $this->jsonFail('读取总览失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'states'       => $states,
            'defs_total'   => (int) ($defs['total'] ?? 0),
            'heartbeat'    => $heartbeat,
            'last_tick'    => $lastTick,
            'mode'         => $metrics['mode'],
            'enabled'      => $metrics['enabled'],
            'queued_limit_global'  => $metrics['queued_limit_global'],
            'queued_limit_package' => $metrics['queued_limit_package'],
            'concurrent_global'    => $metrics['concurrent_global'],
            'concurrent_package'   => $metrics['concurrent_package'],
        ]);
    }

    /** JSON：周期定义分页检索（namespace / def_key / status）。 */
    public function defs()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $filter = [];
        foreach (['namespace', 'def_key', 'status'] as $key) {
            $value = trim((string) $this->request->get($key, ''));
            if ($value !== '') {
                $filter[$key] = $value;
            }
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        try {
            $result = $this->store()->listDefs($filter, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：执行实例分页检索（namespace / state / handler / task_key / 日期区间）。 */
    public function runs()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $filter = [];
        foreach (['namespace', 'state', 'handler', 'task_key', 'date_from', 'date_to'] as $key) {
            $value = trim((string) $this->request->get($key, ''));
            if ($value !== '') {
                $filter[$key] = $value;
            }
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        try {
            $result = $this->store()->listRuns($filter, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** 暂停周期定义。 */
    public function pauseDef()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        [$namespace, $key] = $this->defTarget();
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 def_key');
        }
        try {
            $hit = $this->store()->setDefEnabled($namespace, $key, false);
        } catch (\Throwable $e) {
            return $this->jsonFail('暂停失败：' . $e->getMessage());
        }
        if (!$hit) {
            return $this->jsonFail('周期定义不存在', 404);
        }
        $this->audit($gate, 'scheduler.pauseDef', $namespace . '/' . $key);
        return $this->jsonOk(null, '已暂停');
    }

    /** 恢复周期定义（重算下一次执行时间）。 */
    public function resumeDef()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        [$namespace, $key] = $this->defTarget();
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 def_key');
        }
        try {
            $hit = $this->service()->resume($namespace, $key);
        } catch (\Throwable $e) {
            return $this->jsonFail('恢复失败：' . $e->getMessage());
        }
        if (!$hit) {
            return $this->jsonFail('周期定义不存在', 404);
        }
        $this->audit($gate, 'scheduler.resumeDef', $namespace . '/' . $key);
        return $this->jsonOk(null, '已恢复');
    }

    /** 删除周期定义（仅删定义，不删已产生的实例）。 */
    public function deleteDef()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        [$namespace, $key] = $this->defTarget();
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 def_key');
        }
        try {
            $hit = $this->store()->deleteDef($namespace, $key);
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
        if (!$hit) {
            return $this->jsonFail('周期定义不存在', 404);
        }
        $this->audit($gate, 'scheduler.deleteDef', $namespace . '/' . $key);
        return $this->jsonOk(null, '已删除');
    }

    /** 立即触发一次周期定义（使用定义上的 handler 与 payload）。 */
    public function triggerNow()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        [$namespace, $key] = $this->defTarget();
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 def_key');
        }
        try {
            $runId = $this->service()->triggerNow($namespace, $key);
        } catch (\Throwable $e) {
            return $this->jsonFail('触发失败：' . $e->getMessage());
        }
        $this->audit($gate, 'scheduler.triggerNow', $namespace . '/' . $key);
        return $this->jsonOk(['run_id' => $runId], '已加入队列');
    }

    /** 取消执行实例：queued 直接取消，running 打取消标记，终态拒绝。 */
    public function cancelRun()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $runId = trim((string) $this->request->post('run_id', ''));
        if ($runId === '') {
            return $this->jsonFail('缺少 run_id');
        }
        try {
            $row = $this->store()->findRun($runId);
            if ($row === null) {
                return $this->jsonFail('执行实例不存在', 404);
            }
            $namespace = (string) ($row['namespace'] ?? '');
            $hit = match ((string) ($row['state'] ?? '')) {
                'queued'  => $this->store()->cancelQueuedRun($runId, $namespace),
                'running' => $this->store()->requestRunCancel($runId, $namespace),
                default   => false,
            };
        } catch (\Throwable $e) {
            return $this->jsonFail('取消失败：' . $e->getMessage());
        }
        if (!$hit) {
            return $this->jsonFail('实例已结束或不可取消');
        }
        $this->audit($gate, 'scheduler.cancelRun', $runId);
        return $this->jsonOk(null, '已提交取消');
    }

    /** JSON：42 项配置生效值 + 配置表结构 + env 硬顶。 */
    public function settings()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $settings = $this->schedulerSettings();
            $all = $settings->all();
            $schema = $settings->schema();
        } catch (\Throwable $e) {
            return $this->jsonFail('读取配置失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'values'   => $all,
            'schema'   => $schema,
            'env_caps' => [
                'max_concurrent'  => (int) Env::get('SCHEDULER_MAX_CONCURRENT', 64),
                'max_timeout_sec' => (int) Env::get('SCHEDULER_MAX_TIMEOUT_SEC', 1800),
            ],
        ]);
    }

    /** 批量保存配置（部分更新，任一非法整体拒绝）。 */
    public function settingsSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $raw = $this->request->post('values', '');
        $patch = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $this->request->post();
        if (!is_array($patch)) {
            return $this->jsonFail('配置格式非法');
        }
        try {
            $all = $this->schedulerSettings()->save($patch);
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
        $this->audit($gate, 'scheduler.settingsSave', 'keys=' . implode(',', array_keys($patch)));
        return $this->jsonOk(['values' => $all], '已保存');
    }

    /** 恢复默认配置。 */
    public function settingsReset()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $all = $this->schedulerSettings()->reset();
        } catch (\Throwable $e) {
            return $this->jsonFail('恢复失败：' . $e->getMessage());
        }
        $this->audit($gate, 'scheduler.settingsReset', 'defaults');
        return $this->jsonOk(['values' => $all], '已恢复默认配置');
    }

    /** JSON：环境自检（fresh=1 强制刷新缓存）。 */
    public function probe()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $fresh = (int) $this->request->get('fresh', 0) === 1;
        try {
            $result = (new SchedulerProbe($this->store(), $this->schedulerSettings()))->probe($fresh);
        } catch (\Throwable $e) {
            return $this->jsonFail('自检失败：' . $e->getMessage());
        }
        return $this->jsonOk($result);
    }

    /** JSON：常驻心跳部署命令文本（只生成不执行）。 */
    public function installCommands()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $interval = trim((string) $this->request->get('interval', '1'));
        try {
            $commands = (new SchedulerInstaller())->commands(PHP_OS_FAMILY, $interval);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonFail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonFail('生成失败：' . $e->getMessage());
        }
        return $this->jsonOk($commands);
    }

    /** 手动执行一次 tick（与请求尾/心跳共用同一把互斥锁）。 */
    public function tickNow()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $summary = (new SchedulerRunner($this->store(), $this->schedulerSettings(), new TaskHandlerLoader()))
                ->tick('admin');
        } catch (\Throwable $e) {
            return $this->jsonFail('执行失败：' . $e->getMessage());
        }
        $this->audit($gate, 'scheduler.tickNow', (string) ($summary['message'] ?? ''));
        return $this->jsonOk($summary, (string) ($summary['message'] ?? 'ok'));
    }

    /** 读取周期定义定位参数（POST：namespace + def_key）。 */
    private function defTarget(): array
    {
        return [
            trim((string) $this->request->post('namespace', '')),
            trim((string) $this->request->post('def_key', '')),
        ];
    }

    /** 超管操作留痕，失败不阻断。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
