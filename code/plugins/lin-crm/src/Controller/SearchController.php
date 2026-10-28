<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\Request;

/**
 * 全局搜索与权限下发（GUI 打磨配套）：
 *  - search：跨客户/线索/商机/合同检索，分区按功能权限过滤，行权限沿用 scope()；
 *  - perms：下发当前用户 lin-crm:* 权限码 + 用户 id，供前端按钮显隐。
 * 注意：显隐不构成授权（RL-4），后端各接口仍强制 checkPerm。
 */
class SearchController extends LinCrmBaseController
{
    /** 全局搜索（POST lin-crm/search {keyword}） */
    public function search(Request $req)
    {
        $kw = trim((string) $req->param('keyword', ''));
        $empty = ['customer' => [], 'lead' => [], 'opportunity' => [], 'contract' => []];
        if ($kw === '') { return $this->ok($empty); }
        $like = '%' . $kw . '%';

        if ($this->hasPerm('lin-crm:customer:view')) {
            $q = \think\facade\Db::name('lg_lin-crm_customer')->whereNull('deleted_at')
                ->where(function ($w) use ($like, $kw) {
                    $w->whereLike('name', $like)->whereOr('phone', 'like', $kw . '%');
                });
            $this->scope($q);
            $empty['customer'] = $q->field('id,code,name,phone,pool_status')->order('updated_at', 'desc')->limit(5)->select()->toArray();
        }
        if ($this->hasPerm('lin-crm:lead:view')) {
            $q = \think\facade\Db::name('lg_lin-crm_lead')->whereNull('deleted_at')
                ->where(function ($w) use ($like, $kw) {
                    $w->whereLike('name', $like)->whereOr('phone', 'like', $kw . '%');
                });
            $this->scope($q);
            $empty['lead'] = $q->field('id,code,name,phone,status')->order('updated_at', 'desc')->limit(5)->select()->toArray();
        }
        if ($this->hasPerm('lin-crm:opportunity:view')) {
            $q = \think\facade\Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->whereLike('name', $like);
            $this->scope($q);
            $empty['opportunity'] = $q->field('id,code,name,stage,status,amount')->order('updated_at', 'desc')->limit(5)->select()->toArray();
        }
        if ($this->hasPerm('lin-crm:contract:view')) {
            $q = \think\facade\Db::name('lg_lin-crm_contract')->whereNull('deleted_at')
                ->where(function ($w) use ($like) {
                    $w->whereLike('name', $like)->whereOr('code', 'like', $like);
                });
            $this->scope($q);
            $empty['contract'] = $q->field('id,code,name,status,amount')->order('updated_at', 'desc')->limit(5)->select()->toArray();
        }
        return $this->ok($empty);
    }

    /** 当前用户 lin-crm 权限码 + 用户 id（GET lin-crm/perms） */
    public function perms()
    {
        $codes = [];
        try {
            $bootstrap = new \linhub\host\support\HostBootstrap();
            $identity  = $bootstrap->currentUser();
            $codes = $identity ? $bootstrap->userPerms($identity) : [];
        } catch (\Throwable) {
            $codes = [];
        }
        $perms = array_values(array_filter(
            (array) $codes,
            static fn($p): bool => is_string($p) && str_starts_with($p, 'lin-crm:')
        ));
        return $this->ok(['perms' => array_values($perms), 'user_id' => $this->currentUserId()]);
    }
}
