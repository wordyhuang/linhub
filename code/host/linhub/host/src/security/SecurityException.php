<?php

declare(strict_types=1);

namespace linhub\host\security;

/**
 * 安全、管控与审计统一异常（T7 / C2 / C3 / R4）
 *
 * 用于平台准入被拒、高危操作被拦截等安全场景的统一异常类型。
 * 继承自 \RuntimeException，便于上层按安全语义统一捕获。
 */
final class SecurityException extends \RuntimeException
{
}