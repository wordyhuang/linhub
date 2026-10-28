<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\attachment\AttachmentService;
use linhub\host\services\dictionary\DictionaryService;
use linhub\host\services\inbox\InboxService;
use linhub\host\services\loginstate\LoginStateService;
use linhub\host\services\ServiceRegistry;

/**
 * 平台公共服务总览看板（PS-1）—— admin 应用，只读
 *
 *  - GET /admin/platform_service/index   看板空壳（前端拉 stats 渲染）
 *  - GET /admin/platform_service/stats   四服务用量汇总 JSON
 *
 * 只读约束：本页不承载任何写操作；附件/字典/消息的管理动作在各自管理页完成。
 */
class PlatformService extends AdminController
{
    /** 看板页。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('platform_service/index');
    }

    /** JSON：四服务用量汇总。 */
    public function stats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $attachment = new AttachmentService();
        $dictionary = new DictionaryService();
        $inbox      = new InboxService();
        $loginState = new LoginStateService();

        return $this->jsonOk([
            'attachment' => $attachment->stats(),
            'dictionary' => $dictionary->stats(),
            'inbox'      => $inbox->stats(),
            'loginState' => $loginState->snapshot(),
            // 近 7 天消息发送趋势（按天计数，无消息的日期补 0）
            'inboxTrend' => $this->inboxTrend($inbox, 7),
            // 近 7 天新增附件趋势（含已删，按 created_at 按天聚合）
            'attachmentTrend' => $attachment->trend(7),
            // 四个可见 host 服务的契约版本号（V7.29，事实源 services.php）
            'versions' => [
                'attachment' => ServiceRegistry::hostVersion('attachment'),
                'dictionary' => ServiceRegistry::hostVersion('dictionary'),
                'loginState' => ServiceRegistry::hostVersion('loginstate'),
                'inbox'      => ServiceRegistry::hostVersion('inbox'),
            ],
        ]);
    }

    /**
     * 近 N 天消息投递趋势（按 lg_mgt_message.created_at 日期聚合）。
     *
     * @return list<array{date:string,total:int,recipients:int}>
     */
    private function inboxTrend(InboxService $inbox, int $days): array
    {
        // 复用 paginateMessages 无法按天聚合，这里经服务层 stats 同级只读查询：
        // 直接以较大分页取近 N 天消息在 PHP 侧聚合，避免给服务契约增加专用方法。
        $fromDay = date('Y-m-d', time() - ($days - 1) * 86400);
        // created_at 以 date('c')（含时区偏移）落库，比较值也用同口径 ISO8601
        $fromIso = date('c', (int) strtotime($fromDay . ' 00:00:00'));
        $page = $inbox->paginateMessages(['date_from' => $fromIso], 1, 200);

        $bucket = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $bucket[date('Y-m-d', time() - $i * 86400)] = ['total' => 0, 'recipients' => 0];
        }
        foreach ((array) ($page['items'] ?? []) as $row) {
            $day = substr((string) ($row['created_at'] ?? ''), 0, 10);
            if (isset($bucket[$day])) {
                $bucket[$day]['total']++;
                $bucket[$day]['recipients'] += (int) ($row['recipient_count'] ?? 0);
            }
        }
        $out = [];
        foreach ($bucket as $date => $v) {
            $out[] = ['date' => $date, 'total' => $v['total'], 'recipients' => $v['recipients']];
        }
        return $out;
    }
}
