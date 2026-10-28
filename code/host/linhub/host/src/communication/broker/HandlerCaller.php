<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * 处理器调用器（T6.3 / N1 / N6）
 *
 * 统一的 handler 实例化与调用入口。handler 为类名，需提供无参构造
 * 及 `handle(Event $event)` 方法。失败信息直观暴露，由调用方决定是否重试。
 */
final class HandlerCaller
{
    private function __construct()
    {
    }

    /** 判断类是否为可调用的处理器（存在 handle 方法）。 */
    public static function isHandler(string $handlerClass): bool
    {
        return class_exists($handlerClass)
            && (new \ReflectionClass($handlerClass))->hasMethod('handle');
    }

    /**
     * 调用处理器。
     *
     * @throws \Throwable 处理器抛出的异常透传，由调用方（Broker/订阅表）决定重试与吞掉。
     */
    public static function call(string $handlerClass, Event $event): void
    {
        $ref = new \ReflectionClass($handlerClass);
        $instance = $ref->newInstance();
        $method = $ref->getMethod('handle');
        $method->invoke($instance, $event);
    }
}