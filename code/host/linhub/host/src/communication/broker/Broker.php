<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * 事件总线 Broker 契约（T6.3 / A7 / N1 / N2 / N6）
 *
 * 面向功能包提供事件发布与订阅能力。topic 必须带 `lg.<short>.` 前缀（N5）。
 * 契约强调：发布方 publish 不得因消费者失败而抛错；投递失败由宿主侧重试，不影响发布方。
 *
 * 实现：MemoryBroker（进程内，可离线验证）/ RedisStreamBroker（基于 Redis Stream 骨架）。
 */
interface Broker
{
    /**
     * 订阅一个主题。
     *
     * @param string $topic   事件主题
     * @param string $handler 处理器类名（需实现 handle(Event $event)））
     * @param string $package 归属功能包 short（默认空串表示宿主）
     */
    public function subscribe(string $topic, string $handler, string $package = ''): void;

    /**
     * 发布事件到指定主题。
     *
     * @param string $topic   事件主题
     * @param array<string,mixed> $payload 事件载荷
     * @param string $source  事件来源（app_id / 'platform'）
     * @return array<string,mixed> 投递结果摘要（绝不因消费者失败而抛异常）
     */
    public function publish(string $topic, array $payload = [], string $source = 'platform'): array;
}