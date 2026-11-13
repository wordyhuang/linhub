<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

use linhub\host\services\dictionary\DictionaryService;

/**
 * 审批服务设置——接入平台键值对服务（数据字典）持久化。
 *
 * 存储位置：namespace = host（平台自身命名空间）、key = approval.settings，
 * value 为 JSON 对象整体保存；新增配置项无需加 key，仅需扩充 DEFAULTS 白名单。
 * 生效点读取（workbenchEnabled）故障兜底默认值，避免字典服务异常阻断审批业务。
 */
final class ApprovalSettings
{
    /** 平台自身命名空间（同 ApprovalService::HOST_NAMESPACE）。 */
    public const NS = 'host';

    /** 字典业务 key。 */
    public const KEY = 'approval.settings';

    /** 配置项白名单与默认值。 */
    private const DEFAULTS = [
        'workbench_enabled'       => true,  // 是否启用审批工作台（页面入口）
        'workbench_api_enabled'   => true,  // 是否启用我的待办/已办数据接口（与页面开关相互独立）
        'workbench_stats_enabled' => false, // 是否在角色首页显示个人审批统计卡（需工作台页面开关同时开启）
        'notify_enabled'          => true,  // 是否开启审批通知（工作台右上角审批通知入口）
    ];

    private DictionaryService $dict;

    public function __construct(?DictionaryService $dict = null)
    {
        $this->dict = $dict ?? new DictionaryService();
    }

    /** 全量设置（白名单过滤 + 默认值兜底）。 */
    public function all(): array
    {
        $raw = $this->dict->get(self::NS, self::NS, self::KEY, []);
        if (!is_array($raw)) {
            return self::DEFAULTS;
        }
        return array_intersect_key($raw + self::DEFAULTS, self::DEFAULTS);
    }

    /** 审批工作台是否启用（默认/故障兜底 true，保持向后兼容）。 */
    public function workbenchEnabled(): bool
    {
        try {
            $v = $this->all()['workbench_enabled'] ?? true;
        } catch (\Throwable) {
            return true;
        }
        return is_bool($v) ? $v : in_array($v, [1, '1', 'true'], true);
    }

    /** 我的待办/已办数据接口是否启用（默认/故障兜底 true，与工作台页面开关相互独立）。 */
    public function workbenchApiEnabled(): bool
    {
        try {
            $v = $this->all()['workbench_api_enabled'] ?? true;
        } catch (\Throwable) {
            return true;
        }
        return is_bool($v) ? $v : in_array($v, [1, '1', 'true'], true);
    }

    /** 角色首页个人审批统计卡是否显示（默认/故障兜底 false，需工作台页面开关同时开启）。 */
    public function workbenchStatsEnabled(): bool
    {
        try {
            $v = $this->all()['workbench_stats_enabled'] ?? false;
        } catch (\Throwable) {
            return false;
        }
        return is_bool($v) ? $v : in_array($v, [1, '1', 'true'], true);
    }

    /** 审批通知是否开启（默认/故障兜底 true，控制工作台右上角审批通知入口显隐）。 */
    public function notifyEnabled(): bool
    {
        try {
            $v = $this->all()['notify_enabled'] ?? true;
        } catch (\Throwable) {
            return true;
        }
        return is_bool($v) ? $v : in_array($v, [1, '1', 'true'], true);
    }

    /**
     * 保存设置：白名单合并后整体 JSON 持久化，返回保存后的全量设置。
     *
     * @throws \Throwable 字典服务异常时向上抛出（由调用方决定提示）
     */
    public function save(array $input): array
    {
        $merged = $this->all();
        if (array_key_exists('workbench_enabled', $input)) {
            $merged['workbench_enabled'] = (bool) $input['workbench_enabled'];
        }
        if (array_key_exists('workbench_api_enabled', $input)) {
            $merged['workbench_api_enabled'] = (bool) $input['workbench_api_enabled'];
        }
        if (array_key_exists('workbench_stats_enabled', $input)) {
            $merged['workbench_stats_enabled'] = (bool) $input['workbench_stats_enabled'];
        }
        if (array_key_exists('notify_enabled', $input)) {
            $merged['notify_enabled'] = (bool) $input['notify_enabled'];
        }
        $this->dict->put(self::NS, self::KEY, $merged, [
            'is_public' => false,
            'remark'    => '审批服务设置（平台托管）',
        ]);
        return $merged;
    }
}
