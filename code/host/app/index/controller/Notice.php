<?php

declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use linhub\host\services\approval\ApprovalService;
use linhub\host\services\approval\ApprovalSettings;
use linhub\host\services\inbox\InboxService;
use linhub\host\support\HostBootstrap;
use think\Response;

/**
 * 外框架通知中心 JSON API（IM-7 ~ IM-13）。
 *
 * 全部接口仅服务当前登录用户；未登录统一返回业务码 -401（HTTP 200），
 * 与管理端 RequireLogin 的 JSON 约定保持一致，前端据此静默收起通知面板。
 */
class Notice extends BaseController
{
    /** 未读数（红点徽章轻量接口，IM-7）。 */
    public function unreadCount(): Response
    {
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        return $this->jsonOk(['unread' => (new InboxService())->unreadCount($identity)]);
    }

    /** 审批入口徽章数（工作台右上角审批通知入口）：enabled 取审批服务设置，pending 为该用户当前待审批单数（与审批工作台「我的待办」同口径）。 */
    public function approvalPendingCount(): Response
    {
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        $enabled = true;
        try {
            $enabled = (new ApprovalSettings())->notifyEnabled();
        } catch (\Throwable) {
            $enabled = true;
        }
        $pending = 0;
        if ($enabled) {
            try {
                $summary = (new ApprovalService())->myWorkbenchSummary($identity);
                $pending = (int) ($summary['todo'] ?? 0);
            } catch (\Throwable) {
                $pending = 0;
            }
        }
        return $this->jsonOk(['enabled' => $enabled, 'pending' => $pending]);
    }

    /** 当前用户收件箱（IM-8/9/11），filter=all|unread|read，默认 all。 */
    public function list(): Response
    {
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        $filter = strtolower(trim((string) $this->request->param('filter', 'all')));
        if (!in_array($filter, ['all', 'unread', 'read'], true)) {
            $filter = 'all';
        }
        $page = (int) $this->request->param('page', 1);
        $size = (int) $this->request->param('size', 20);

        $data = (new InboxService())->inbox($identity, $filter, $page, $size);
        return $this->jsonOk($data);
    }

    /** 标记单条已读（IM-10/13，幂等），返回刷新后的未读数。 */
    public function read(): Response
    {
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        $msgKey = trim((string) $this->request->param('msg_key', ''));
        $service = new InboxService();
        $service->markRead($identity, $msgKey);
        return $this->jsonOk(['unread' => $service->unreadCount($identity)]);
    }

    /** 全部已读（IM-12），返回本次置已读条数与刷新后的未读数（恒 0）。 */
    public function readAll(): Response
    {
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        $service = new InboxService();
        $affected = $service->markAllRead($identity);
        return $this->jsonOk(['affected' => $affected, 'unread' => 0]);
    }

    private function jsonOk(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 0, 'data' => $data, 'message' => $message]);
    }

    private function jsonFail(string $message, int $code = -1): Response
    {
        return json(['code' => $code, 'data' => null, 'message' => $message]);
    }
}
