<?php

declare(strict_types=1);

namespace app\admin\middleware;

use linhub\host\support\HostBootstrap;
use Closure;

/**
 * admin 应用登录校验（中后台统一守卫）
 *
 * 约定：admin 应用下所有内容均需登录；仅 Auth 控制器的 login/doLogin 放行
 * （登录页与登录提交本身）。未登录：普通页面请求 → 302 重定向 /admin/auth/login；
 * 异步请求（isAjax 或 Accept: application/json，兼容 axios 1.x 不带 X-Requested-With）
 * → 返回标准 JSON {code:-401,data:null,message}，由前端弹窗提示。
 * 免登录的公开功能请自建独立应用承载，不要放进 admin。
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
            // 异步请求不重定向，返回标准 JSON 对象（HTTP 200 + body.code）。
            // isAjax 仅认 X-Requested-With，而 axios 1.x 默认不带该头，
            // 但其 Accept 以 application/json 开头，故用 isJson 兜底识别。
            // 业务码取 -401：workdesktop-ai 约定 code >= successCode(默认0) 为成功，
            // 正 401 会被误判成功，必须用负数才会走 fail 分支，由 WD.request 弹 message 提示。
            if ($request->isAjax() || $request->isJson()) {
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
