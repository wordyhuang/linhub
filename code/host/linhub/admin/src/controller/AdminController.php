<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use linhub\capability\auth\Identity;
use linhub\host\services\setting\SiteSettingService;
use linhub\host\support\HostBootstrap;
use linhub\host\Version;
use think\facade\Session;
use think\Response;

/**
 * 中后台超管管理页控制器基类（G0.5/G0.6）
 *
 * 职责：
 *  - 初始化宿主装配（HostBootstrap）并幂等保证平台权限点/管理菜单就绪；
 *  - 统一超管门禁：应用级 RequireLogin 已保证登录，本基类再保证仅超管可入（C2）；
 *  - 统一 WdStation 管理页公共数据（用户 / 侧边栏菜单 / flash 消息）与菜单树转换，消除重复。
 *
 * 用法（每个管理动作开头）：
 *   $gate = $this->gate();
 *   if (!$gate instanceof Identity) { return $gate; }
 *   $identity = $gate;
 */
abstract class AdminController extends BaseController
{
    protected HostBootstrap $bootstrap;

    protected function initialize(): void
    {
        $this->bootstrap = new HostBootstrap($this->app);
        // 幂等：平台权限点、超管功能菜单组、老环境菜单迁移
        $this->bootstrap->ensureSeeded();
        // 全局设置站点名注入模板变量（浏览器 title 等品牌后缀统一从这里提取）
        $this->app->view->assign('siteName', (string) (new SiteSettingService())->get('site_name', SiteSettingService::DEFAULT_SITE_NAME));
    }

    /** 当前登录身份；未登录返回 null（正常已被应用中间件拦截）。 */
    protected function identity(): ?Identity
    {
        return $this->bootstrap->currentUser(HostBootstrap::authService());
    }

    /**
     * 平台管理门禁。
     *
     * @return Identity|Response 返回 Identity=超管放行；否则返回应直接输出的响应
     *                           （未登录 302 登录页；已登录非超管 403）
     */
    protected function gate(): Identity|Response
    {
        $identity = $this->identity();
        if ($identity === null) {
            return redirect('/admin/auth/login');
        }
        if (!HostBootstrap::authService()->isSuperAdmin($identity)) {
            return $this->forbidden();
        }
        return $identity;
    }

    /** 403 页面（已登录但非超管）。 */
    protected function forbidden(): Response
    {
        return response($this->app->view->fetch('error/403'), 403);
    }

    /**
     * JSON OK 响应（workdesktop-ai 请求协议：code >= successCode(默认0) 视为成功）。
     *
     * @param mixed $data 业务数据；列表类请传 response.list 结构 {list,total,pageSize?,currentPage?}
     */
    protected function jsonOk(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 0, 'data' => $data, 'message' => $message]);
    }

    /** JSON 失败响应。 */
    protected function jsonFail(string $message, int $code = 500, mixed $data = null): Response
    {
        return json(['code' => $code, 'data' => $data, 'message' => $message]);
    }

    /**
     * 平台管理门禁（JSON API 版）。AJAX 请求不应收到 302/HTML，故返回 JSON 错误码。
     *
     * @return Identity|Response 超管返回 Identity；否则返回应直接输出的 JSON 错误响应
     *                           （未登录 -401；已登录非超管 -403）。
     *                           业务码取负数：workdesktop-ai 约定 code>=0 为成功，
     *                           负数才走 fail 分支弹窗；HTTP 状态码仍为 200。
     */
    protected function gateForJson(): Identity|Response
    {
        $identity = $this->identity();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }
        if (!HostBootstrap::authService()->isSuperAdmin($identity)) {
            return $this->jsonFail('无权限访问平台管理接口', -403);
        }
        return $identity;
    }

    /**
     * 组装管理页 WdStation 公共状态：用户、侧边栏菜单、flash 消息；页面专属数据经 $extra 合并。
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function adminWdState(Identity $identity, array $extra = []): array
    {
        $site = (new SiteSettingService())->publicProfile();
        return array_merge([
            'user' => [
                'name'      => (string) ($identity->name ?? ''),
                'logoutUrl' => '/admin/auth/logout',
            ],
            'menuGroups' => $this->menuGroups($identity),
            // 全局设置（站点名/备案号/logo 安全子集），WdStation 外壳品牌统一从这里提取
            'site' => $site,
            // 平台版本（权威来源 Version）；品牌名取全局设置站点名
            'platformVersion' => (string) $site['name'] . ' v' . Version::current(),
            'msg'        => Session::pull('linhub_msg'),
        ], $extra);
    }

    /** 某身份可见的 WdStation 分组菜单。 */
    protected function menuGroups(Identity $identity): array
    {
        return $this->wdMenuGroups($this->bootstrap->menuTreeFor($identity));
    }

    /**
     * 将宿主菜单树转换为 WdStation 需要的分组结构（G0.6 收口自 Home/Package 的两份重复实现）。
     *
     * 每个根节点作为一组（其 child 为组内菜单项）；分导台下未配置任何菜单（子菜单为空）时，
     * 该组渲染为一条 disabled 占位提示「当前分导台暂无菜单」，不生成可跳转菜单项。
     * route 以 / 开头（绝对应用路径）时直接使用，否则回退 url() 生成。
     *
     * @param list<array{item: array<string,mixed>, children: array}> $tree
     * @return list<array{key:string,title:string,icon:string,menus:list<array<string,mixed>>}>
     */
    protected function wdMenuGroups(array $tree): array
    {
        $groups = [];
        $gi     = 0;
        foreach ($tree as $node) {
            $gi++;
            $item  = $node['item'] ?? [];
            $menus = $this->buildMenuItems($node['children'] ?? [], $gi);
            if ($menus === []) {
                // 空分导台占位：disabled 提示项，不可点击、不产生循环导航（UMD 渲染为 el-menu-item disabled）
                $menus = [[
                    'key'      => (string) ($item['id'] ?? 'g' . $gi) . '.empty',
                    'title'    => '当前分导台暂无菜单',
                    'path'     => '',
                    'icon'     => '',
                    'disabled' => true,
                ]];
            }
            $groups[] = [
                'key'   => (string) ($item['id'] ?? 'g' . $gi),
                'title' => (string) ($item['title'] ?? ''),
                'icon'  => (string) ($item['icon'] ?? ''),
                'menus' => $menus,
            ];
        }
        return $groups;
    }

    /**
     * 递归将父菜单树的子节点转换为 WdStation 菜单项：
     *  - 有子项 → { key, title, icon, children }（前端默认渲染为可折叠 el-sub-menu）
     *  - 叶子   → { key, title, path, icon }
     * 节点声明 group 时透传该字段，前端将分导台下一级菜单按 group 值聚合：
     * 相同 group 的节点聚合在同一个标题分组下平铺展示（group 仅对一级菜单生效）。
     *
     * @return list<array{key:string,title:string,icon:string,group?:string,children?:array}|array{key:string,title:string,path:string,icon:string}>
     */
    private function buildMenuItems(array $children, int $gi): array
    {
        $items = [];
        foreach ($children as $child) {
            $ci  = $child['item'] ?? [];
            $base = [
                'key'   => (string) ($ci['id'] ?? 'g' . $gi . 'm' . (count($items) + 1)),
                'title' => (string) ($ci['title'] ?? ''),
                'icon'  => (string) ($ci['icon'] ?? ''),
            ];
            // group 透传给前端：一级菜单按 group 值聚合（相同 group 聚到同一标题分组）
            if ((string) ($ci['group'] ?? '') !== '') {
                $base['group'] = (string) $ci['group'];
            }
            $sub = $child['children'] ?? [];
            if ($sub !== []) {
                $base['children'] = $this->buildMenuItems($sub, $gi);
                $items[] = $base;
            } else {
                $items[] = $this->mapMenuLeaf($ci, $base['key'], $base);
            }
        }
        return $items;
    }

    /** 叶子节点：补齐跳转 path。 */
    private function mapMenuLeaf(array $ci, string $key, array $base = []): array
    {
        $route = (string) ($ci['route'] ?? '');
        $item  = $base !== [] ? $base : [
            'key'   => $key,
            'title' => (string) ($ci['title'] ?? ''),
            'icon'  => (string) ($ci['icon'] ?? ''),
        ];
        $item['path'] = $route === '' ? '' : (str_starts_with($route, '/') ? $route : (string) url($route));
        return $item;
    }
}
