<?php

declare(strict_types=1);

namespace linhub\host\support\middleware;

use linhub\host\support\HostBootstrap;
use Closure;

/**
 * 平台统一登录守卫（中后台唯一门禁实现）。
 *
 * 适用面：
 *  - admin 应用：应用级中间件全量挂载（原 app\admin\middleware\RequireLogin 已收敛到本类）；
 *  - 功能包路由：PackageRouteLoader 对 access=admin 的包统一挂载，功能包不再自建门禁。
 *
 * 放行白名单：登录页与登录提交本身（auth/login、auth/dologin）。
 * 未登录：普通页面请求 → 302 /admin/auth/login?redirect=原路径；异步请求（isAjax 或
 * Accept: application/json，兼容 axios 1.x 不带 X-Requested-With）→ 标准 JSON
 * {code:-401,data:null,message}，由前端 auth-guard.js 弹登录确认框。
 * 业务码取 -401：workdesktop-ai 约定 code >= successCode(默认0) 为成功，正 401 会被误判成功。
 */
class RequireLogin
{
    /** 免登录白名单：{controller}/{action}（小写，不含应用前缀）。 */
    private const ALLOW = [
        'auth/login',
        'auth/dologin',
    ];

    public function handle($request, Closure $next)
    {
        // 多应用下 setApp 已剥离应用前缀，pathinfo 形如 auth/login；兼容带前缀的形态
        $path = strtolower(trim((string) $request->pathinfo(), '/'));
        if (str_starts_with($path, 'admin/')) {
            $path = substr($path, 6);
        }
        if (in_array($path, self::ALLOW, true)) {
            return $next($request);
        }

        $bootstrap = new HostBootstrap();
        $identity  = $bootstrap->currentUser(HostBootstrap::authService());
        if ($identity === null) {
            // 页面导航的 Accept 含 text/html；API 请求（axios/XHR/fetch 默认 */* 或 application/json）
            // 一律回 JSON -401，避免前端因 302 拿到登录页 HTML 而解析失败、弹不出登录确认框。
            $accept = strtolower((string) $request->header('accept', ''));
            if ($request->isJson() || $request->isAjax() || !str_contains($accept, 'text/html')) {
                return json([
                    'code'    => -401,
                    'data'    => null,
                    'message' => '未登录或会话已失效',
                ]);
            }
            // 携带原访问路径，登录成功后按原路跳回（仅保留站内相对路径）
            $uri  = (string) $request->url();
            $back = (str_starts_with($uri, '/') && !str_starts_with($uri, '//'))
                ? '?redirect=' . rawurlencode($uri)
                : '';
            return redirect('/admin/auth/login' . $back);
        }
        return $next($request);
    }
}
