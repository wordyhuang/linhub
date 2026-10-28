<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\facade\Db;

// 包内公共函数加载（now_fmt 等助手）：宿主 PackageRouteLoader 只按 composer psr-4
// 注册类映射、不执行 composer "files" 自动加载，包 src/common.php 必须在基类加载时显式引入。
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'common.php';

/**
 * 林客控制器基类
 * RL-4 双重鉴权：checkPerm() 功能权限（宿主会话）+ scope() 数据行权限四档。
 * 宿主鉴权适配点：仅 checkPerm()/currentUserId() 两处对接宿主，业务代码不直接读会话。
 */
abstract class LinCrmBaseController
{
    // ── 响应 ────────────────────────────────────────────────
    protected function ok($data = null, string $msg = 'ok'): \think\response\Json
    {
        return json(['code' => 0, 'message' => $msg, 'data' => $data]);
    }

    protected function fail(string $msg, int $code = 1): \think\response\Json
    {
        return json(['code' => $code, 'message' => $msg, 'data' => null]);
    }

    // ── 宿主适配点（安装时由宿主服务注入替换实现） ──────────
    /** 当前登录用户 id（宿主登录态，NFR-SEC-01）：兼容宿主 user_id 会话与 linhub_identity 会话 */
    protected function currentUserId(): int
    {
        $uid = session('user_id');
        if (empty($uid)) {
            $ident = session('linhub_identity');
            $uid   = is_array($ident) ? ($ident['id'] ?? 0) : 0;
        }
        return (int) $uid;
    }

    /** 当前宿主登录身份（linhub Identity），未登录返回 null */
    protected function hostIdentity(): ?\linhub\capability\auth\Identity
    {
        try {
            return (new \linhub\host\support\HostBootstrap())->currentUser();
        } catch (\Throwable) {
            return null;
        }
    }

    /** 功能权限校验（权限码来源 permissions.php；前端显隐不构成授权，RL-4） */
    protected function checkPerm(string $perm): void
    {
        if ($this->hasPerm($perm)) {
            return;
        }
        throw new \RuntimeException('permission denied: ' . $perm, 403);
    }

    /** 非抛出式权限判断（超管=全部登记权限码；lg_host_perm_check 注入契约优先） */
    protected function hasPerm(string $perm): bool
    {
        if (function_exists('lg_host_perm_check')) {
            return (bool) lg_host_perm_check($perm);
        }
        try {
            $bootstrap = new \linhub\host\support\HostBootstrap();
            $identity  = $bootstrap->currentUser();
            return $identity !== null && in_array($perm, $bootstrap->userPerms($identity), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 页面渲染（范式对齐：宿主 AdminController::index 的 view->fetch 模式）。
     * 宿主未为包配置 view_path，运行时把视图引擎 view_path 指到包内 src/view/
     * （同 lin-trade TradeController 的引擎分支）；页面为纯 HTML+前端 JS、
     * 实测不含模板标签，引擎渲染无副作用。渲染结果为字符串直接 return，
     * 由框架自动包装为 Html 响应——页面方法不要声明 think\Response 返回类型。
     */
    protected function render(string $template, array $data = []): string
    {
        $viewPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        if (!is_file($viewPath . $template . '.html')) {
            abort(404, '页面缺失：' . htmlspecialchars($template));
        }
        \think\facade\View::engine()->config(['view_path' => $viewPath]);
        return \think\facade\View::fetch('/' . $template, $data);
    }

    /**
     * 读取请求参数：兼容官方 wd-data-grid 卷进 `param` 的嵌套写法与平铺写法，param 优先。
     */
    protected function arg(string $key, $default = null)
    {
        $req = request();
        $all = $req->param();
        $pa  = is_array($all['param'] ?? null) ? $all['param'] : [];
        return array_key_exists($key, $pa) ? $pa[$key] : ($all[$key] ?? $default);
    }

    /**
     * ThinkPHP Paginator → 平台 wd-data-grid 数据形状（{list,total,pageSize,currentPage}）。
     */
    protected function pageShape($paginate): array
    {
        $p = is_array($paginate) ? $paginate : $paginate->toArray();
        return [
            'list'        => array_values((array) ($p['data'] ?? [])),
            'total'       => (int) ($p['total'] ?? 0),
            'pageSize'    => (int) ($p['per_page'] ?? 20),
            'currentPage' => (int) ($p['current_page'] ?? 1),
        ];
    }

    /**
     * 数据行权限四档（FR-AUT-02）：all=全公司 dept_sub=本部门及下级 dept=本部门 self=仅本人
     * 追加到查询 owner_id 条件；四类对象（客户/线索/商机/合同）统一走此入口。
     */
    protected function scope($query, string $alias = ''): object
    {
        $col = ($alias ? $alias . '.' : '') . 'owner_id';
        $mode = function_exists('lg_host_data_scope') ? lg_host_data_scope() : 'self';
        $uid  = $this->currentUserId();
        // 超管全量视角：宿主 RBAC 未提供数据档位函数前，超管恒为 all
        try {
            $identity = $this->hostIdentity();
            if ($identity !== null && \linhub\host\support\HostBootstrap::authService()->isSuperAdmin($identity)) {
                $mode = 'all';
            }
        } catch (\Throwable) {
        }
        switch ($mode) {
            case 'all':
                break;
            case 'dept_sub':
            case 'dept':
                $deptIds = function_exists('lg_host_dept_user_ids') ? lg_host_dept_user_ids($mode) : [$uid];
                $query->whereIn($col, $deptIds);
                break;
            default:
                $query->where($col, $uid);
        }
        return $query;
    }

    // ── 通用组件 ────────────────────────────────────────────
    /** 手机号脱敏（FR-AUT-03）：138****5678 */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 11) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, 7);
    }

    /** 站内信（FR-MSG-01） */
    protected function notify(int $userId, string $title, string $content = '', string $bizType = '', int $bizId = 0): void
    {
        Db::name('lg_lin-crm_notify_log')->insert([
            'user_id' => $userId, 'title' => $title, 'content' => $content,
            'biz_type' => $bizType, 'biz_id' => $bizId,
        ]);
    }

    /** 审计留痕（FR-AUT-04 五类行为：login/export/delete/transfer/phone_view + merge/release） */
    protected function audit(string $action, string $bizType = '', int $bizId = 0, string $detail = ''): void
    {
        Db::name('lg_lin-crm_audit_log')->insert([
            'actor_id' => $this->currentUserId(), 'action' => $action,
            'biz_type' => $bizType, 'biz_id' => $bizId, 'detail' => $detail,
            'ip'       => request()->ip(),
        ]);
    }

    /** 事件发布（宿主事件总线适配点；默认走 think Event，主题前缀 lg.lin-crm.） */
    protected function publish(string $topic, array $payload): void
    {
        \think\facade\Event::trigger($topic, $payload);
    }
}
