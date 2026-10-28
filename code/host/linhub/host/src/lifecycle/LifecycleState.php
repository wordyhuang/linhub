<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 生命周期状态机（T2.8 / G）
 *
 * 统一功能包状态迁移，拒绝非法迁移，并约束各操作只允许在其合法状态发生。
 * 状态常量：absent 未装 / enabled 启用 / disabled 禁用 / deleted 删除。
 * 合法迁移：
 *   - absent → enabled   （install 安装）
 *   - enabled ⇄ disabled （disable 禁用 / enable 启用）
 *   - enabled/disabled → absent （delete 删除，需二次确认）
 * 升级/回退不改变 enabled/disabled 状态（由调用方保持，不经过该迁移）。
 */
final class LifecycleState
{
    /** 未装 */
    public const ABSENT = 'absent';
    /** 启用 */
    public const ENABLED = 'enabled';
    /** 禁用（代码卸载至隔离区、接入点注销；数据保留，可启用恢复） */
    public const DISABLED = 'disabled';
    /** 删除（终态标记） */
    public const DELETED = 'deleted';

    /** 合法迁移表：from => [to, ...] */
    private const TRANSITIONS = [
        self::ABSENT   => [self::ENABLED],
        self::ENABLED  => [self::DISABLED, self::ABSENT],
        self::DISABLED => [self::ENABLED, self::ABSENT],
        self::DELETED  => [],
    ];

    private function __construct()
    {
    }

    /** 状态值是否合法。 */
    public static function isValid(string $state): bool
    {
        return isset(self::TRANSITIONS[$state]);
    }

    /** 判断 from→to 是否为合法迁移。 */
    public static function canTransition(string $from, string $to): bool
    {
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * 执行状态迁移，非法迁移抛异常。
     *
     * @throws \RuntimeException 非法迁移
     */
    public static function transition(string $from, string $to): string
    {
        if (!self::canTransition($from, $to)) {
            throw new \RuntimeException("非法状态迁移：{$from} → {$to}");
        }
        return $to;
    }
}