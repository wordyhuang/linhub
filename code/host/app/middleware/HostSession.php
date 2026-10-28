<?php

declare(strict_types=1);

namespace app\middleware;

use linhub\host\support\HostBootstrap;
use Closure;

/**
 * 宿主会话恢复中间件（T7.5）
 *
 * 依赖 SessionInit 已先执行；从 session['linhub_identity'] 恢复登录身份到
 * AuthService 进程单例，实现跨请求登录态。会话不可用时静默，交由控制器守卫处理。
 */
class HostSession
{
    public function handle($request, Closure $next)
    {
        try {
            $bootstrap = new HostBootstrap();
            $auth      = HostBootstrap::authService();
            if ($auth->currentIdentity() === null) {
                $bootstrap->currentUser($auth);
            }
        } catch (\Throwable $e) {
            // 会话不可用时静默，交给控制器守卫判定
        }
        return $next($request);
    }
}