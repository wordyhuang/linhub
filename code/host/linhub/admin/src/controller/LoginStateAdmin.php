<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\loginstate\LoginStateService;

/**
 * 登录态查看页（LS-6）—— admin 应用，仅超管
 *
 *  - GET /admin/login_state_admin/index  登录态页空壳
 *  - GET /admin/login_state_admin/snapshot 当前登录态只读快照 JSON
 *
 * 供开发者/管理员调试确认身份：登录状态、用户ID、显示名、tags、角色。
 * 本页在超管门禁之后，理论上恒为已登录超管；快照仍走服务统一出口，反映服务真实返回。
 */
class LoginStateAdmin extends AdminController
{
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('login_state_admin/index');
    }

    /** JSON：当前登录态快照（服务真实返回）。 */
    public function snapshot()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $service = new LoginStateService();
        return $this->jsonOk([
            'snapshot' => $service->snapshot(),
            'server'   => [
                'time'  => date('c'),
                'php'   => PHP_VERSION,
            ],
        ]);
    }
}
