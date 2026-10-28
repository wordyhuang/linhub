<?php

declare(strict_types=1);

namespace linhub\host\security;

/**
 * 路由守卫判定结果值对象（T7.5 / D4 / C5 / C6）
 *
 * 免登录公开通道的判定结果：是否放行、原因与安全级别。
 */
final class GuardDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $securityLevel,
    ) {
    }
}