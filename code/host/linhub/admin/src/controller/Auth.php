<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use linhub\host\services\setting\SiteSettingService;
use linhub\host\support\HostBootstrap;

/**
 * 宿主登录 / 登出（T7.2）—— admin 应用
 *
 *  - GET  /admin/auth/login   渲染登录页
 *  - POST /admin/auth/login   登录提交：demo-admin 直走 useMockIdentity 演示；其余走 loginWithPassword
 *  - GET  /admin/auth/logout  清 session 并登出，回登录页
 */
class Auth extends BaseController
{
    /** 登录成功后的默认落点：工作台外框架（内部再按角色加载对应 home 内页）。 */
    private const DASHBOARD_PATH = '/admin/home/dashboard';

    /** 渲染登录表单页。 */
    public function login()
    {
        $error = (string) $this->request->param('error', '');
        $username = (string) $this->request->param('username', '');
        return $this->app->view->fetch('auth/login', [
            'error'    => $error,
            'username' => $username,
            'redirect' => self::safeRedirect((string) $this->request->param('redirect', ''), self::DASHBOARD_PATH),
        ] + $this->siteProfile());
    }

    /** 登录提交：成功写 session 跳转（优先回到 redirect 原页面，否则工作台外框架），失败带错误回登录页。 */
    public function doLogin()
    {
        $bootstrap = new HostBootstrap($this->app);
        $username  = (string) $this->request->post('username', '');
        $password  = (string) $this->request->post('password', '');

        $auth     = HostBootstrap::authService();
        $identity = $bootstrap->performLogin($username, $password, $auth);

        if ($identity === null) {
            $msg = $auth->lastError();
            if ($msg === '') {
                $msg = '用户名或密码错误';
            }
            return $this->app->view->fetch('auth/login', [
                'error'    => $msg,
                'username' => $username,
                'redirect' => self::safeRedirect((string) $this->request->post('redirect', '')),
            ] + $this->siteProfile());
        }

        $bootstrap->saveLoginToSession($identity);
        // 默认进入工作台外框架；角色首页由 dashboard 按 roleHomePath 在主区 iframe 内加载，不直接跳到内页
        $redirect = self::safeRedirect((string) $this->request->post('redirect', ''), self::DASHBOARD_PATH);
        return redirect($redirect);
    }

    /** 登录页可见的全局设置（站点名/备案号/logo 的安全子集）。 */
    private function siteProfile(): array
    {
        return (new SiteSettingService())->publicProfile();
    }

    /**
     * 校验跳转目标仅为站内相对路径，防开放重定向。
     * 只接受以单个 / 开头、不以 // 开头、不含反斜杠与换行的路径；否则回退 $fallback。
     */
    private static function safeRedirect(string $url, string $fallback = '/admin/auth/login'): string
    {
        $url = trim($url);
        if ($url !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//')
            && !str_contains($url, '\\') && strpbrk($url, "\r\n") === false) {
            return $url;
        }
        return $fallback;
    }

    /** 登出：通知 AuthService 失效会话并清 session，跳登录页。 */
    public function logout()
    {
        $bootstrap = new HostBootstrap($this->app);
        try {
            HostBootstrap::authService()->logout();
        } catch (\Throwable $e) {
            // 远端失效失败不阻断本地登出
        }
        $bootstrap->clearLoginSession();
        return redirect('/admin/auth/login');
    }
}