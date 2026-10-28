<?php

declare(strict_types=1);

namespace linhub\host\services\messaging;

/**
 * 消息/事件发布契约（T1.7 / M3 / N 组）
 *
 * 面向功能包的事件发布与订阅能力；订阅与定向分发由宿主事件总线实现。
 * topic 必须为分段主题：`lg.<short>.`（本方/跨包，N5）或宿主保留前缀 `lg.platform.`。
 */
interface Messaging
{
    /** 发布一个事件到指定主题。 */
    public function publish(string $topic, array $payload = []): void;

    /** 订阅主题，注册处理器（handler 为订阅者类，需提供无参构造及 handle(Event $event)）。 */
    public function subscribe(string $topic, string $handler): void;
}