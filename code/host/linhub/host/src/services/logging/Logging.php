<?php

declare(strict_types=1);

namespace linhub\host\services\logging;

/**
 * 日志服务契约（T1.7 / M4 / M8）
 *
 * 面向功能包暴露的只读日志能力。功能包经 services.requires 声明后由宿主注入接口实例。
 * 契约规范：消费语义优先、"新增不改旧"（新增服务另建新接口，不修改本接口）。
 */
interface Logging
{
    /**
     * 记录一条 info 级别日志（带结构化上下文）。
     *
     * @param string $message 日志消息
     * @param array<string,mixed> $context 结构化上下文（如 package_id / 业务字段）
     */
    public function info(string $message, array $context = []): void;

    /**
     * 记录一条 warning 级别日志。
     */
    public function warning(string $message, array $context = []): void;

    /**
     * 记录一条 error 级别日志。
     */
    public function error(string $message, array $context = []): void;

    /**
     * 记录一条 debug 级别日志。
     */
    public function debug(string $message, array $context = []): void;
}