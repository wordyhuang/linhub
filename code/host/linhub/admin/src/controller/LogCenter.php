<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\logging\LogFileService;
use linhub\host\services\logging\LogSettingService;

/**
 * 平台服务管理 · 日志服务（工作台 / 日志设置）
 *
 * 菜单：menu.superadmin.logsvc（日志服务工作台、日志审计[复用 audit]、日志设置）。
 * 页面壳由视图渲染，数据经下列 JSON 接口加载：
 *  - GET  /admin/LogCenter/stats    运行日志汇总（文件数/大小/月度归档/今日各级别）
 *  - GET  /admin/LogCenter/recent   最近日志（level / limit 过滤）
 *  - GET  /admin/LogCenter/state    日志设置 + 存储概览
 *  - POST /admin/LogCenter/save     保存日志设置
 *  - POST /admin/LogCenter/cleanup  按保留月数清理过期日志
 */
class LogCenter extends AdminController
{
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('log_center/index');
    }

    public function settings()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('log_center/settings');
    }

    /** JSON：运行日志汇总统计。 */
    public function stats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk($this->fileService()->stats());
    }

    /** JSON：最近日志条目（level 空=全部；limit 1~500）。 */
    public function recent()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        return $this->jsonOk([
            'list' => $this->fileService()->recent((int) $req->get('limit', '100'), (string) $req->get('level', '')),
        ]);
    }

    /** JSON：日志设置页初始状态（设置 + 存储概览）。 */
    public function state()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk([
            'settings' => $this->settingService()->all(),
            'storage'  => $this->fileService()->stats(),
            'levels'   => [
                ['value' => 'debug', 'label' => 'DEBUG（全量）'],
                ['value' => 'info', 'label' => 'INFO（常规及以上）'],
                ['value' => 'warning', 'label' => 'WARNING（警告及以上）'],
                ['value' => 'error', 'label' => 'ERROR（仅错误）'],
            ],
        ]);
    }

    /** JSON：保存日志设置（enabled / min_level / retention_months）。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $post = (array) $this->request->post();
        $service = $this->settingService();
        $before = $service->all();
        $after = $service->save([
            'enabled'          => $post['enabled'] ?? $before['enabled'],
            'min_level'        => $post['min_level'] ?? $before['min_level'],
            'retention_months' => $post['retention_months'] ?? $before['retention_months'],
        ], (string) ($gate->name ?? $gate->id ?? ''));
        $this->bootstrap->auditTrail()->log('logging.setting', 'log_setting', (string) ($gate->name ?? ''), [
            'via'      => 'admin-gui',
            'before'   => $before,
            'after'    => $after,
        ]);
        return $this->jsonOk($after, '日志设置已保存');
    }

    /** JSON：按当前保留月数清理过期月份目录。 */
    public function cleanup()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $service = $this->settingService();
        $retention = $service->retentionMonths();
        $result = $this->fileService()->cleanup($retention);
        $this->bootstrap->auditTrail()->log('logging.cleanup', 'log_files', (string) ($gate->name ?? ''), [
            'via'              => 'admin-gui',
            'retention_months' => $retention,
            'result'           => $result,
        ]);
        return $this->jsonOk([
            'result'  => $result,
            'storage' => $this->fileService()->stats(),
        ], '过期日志清理完成');
    }

    private function fileService(): LogFileService
    {
        return new LogFileService();
    }

    private function settingService(): LogSettingService
    {
        return new LogSettingService();
    }
}
