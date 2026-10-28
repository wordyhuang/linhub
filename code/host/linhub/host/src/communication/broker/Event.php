<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * 事件对象（T6.3 / N2 / 审计留数据）
 *
 * 发布事件时统一封装：携带主题、载荷、发生时间、来源与唯一 id，
 * 为审计/安全留痕（S7 审计贯通的数据底座）。
 */
final class Event
{
    /**
     * @param string $topic      事件主题（带 `lg.<short>.` 前缀，N5）
     * @param array<string,mixed> $payload 事件载荷
     * @param float $occurredAt  事件发生时间戳（microtime）
     * @param string $source     事件来源（app_id / 'platform'）
     * @param string $id         事件唯一 id，用于去重/审计
     */
    public function __construct(
        public readonly string $topic,
        public readonly array $payload,
        public readonly float $occurredAt,
        public readonly string $source,
        public readonly string $id,
    ) {
    }
}