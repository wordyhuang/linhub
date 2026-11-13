<?php

declare(strict_types=1);

/*
 * 文档创建时间：2026-10-20
 * 最后修改时间：2026-11-11
 * 当前版本：V2.1
 * 适用对象：宿主管理端（admin 应用）
 * 核心目标：角色管理 GUI——仅展示 5 个系统预设角色与管理成员绑定，角色资料只读、成员可调
 * 文档简略描述：V2.1 成员口径放开：所有预设角色（含平台占位的超级管理员）成员均可调整；角色资料（名称/备注）只读。
 */

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\rbac\RbacRoles;

/**
 * 角色管理 GUI（G1 / T5.2 / J2 / J3）—— admin 应用（V2.1 角色资料只读、成员可调）
 *
 *  - GET  /admin/role/index     角色列表（空壳，配 wd-* 组件）
 *  - GET  /admin/role/rows      角色列表 JSON（5 预设角色 + 每角色用户数）
 *  - GET  /admin/role/users     成员选择数据源 JSON（全部用户 + 该角色已关联用户 id）
 *  - POST /admin/role/usersSave 整体替换角色成员，JSON
 *
 * 角色仅承载预设身份语义（J2），【不】绑定权限（J3），页面不提供挂权限入口；
 * 角色资料（名称/备注）只读、不可新增/改名/删除（V2.0 收缩）；成员绑定对所有预设角色均可调整（V2.1，含超管）。
 * 成员保存成功路径写 AuditTrail（action=role.users）。
 */
class Role extends AdminController
{
    /**
     * 角色列表：5 预设角色 + 每角色用户数。
     *
     * @param string $keyword 可选检索词，按角色名 / 备注 / 角色 id 不区分大小写匹配
     */
    private function roleRows(string $keyword = ''): array
    {
        $this->bootstrap->roles()->ensureDefaultRoles();

        // 每角色用户数聚合（RbacUser.rolesOf）
        $userCounts = [];
        foreach ($this->bootstrap->rbacUsers()->all() as $u) {
            foreach ($this->bootstrap->rbacUsers()->rolesOf((string) ($u['id'] ?? '')) as $rid) {
                $userCounts[$rid] = ($userCounts[$rid] ?? 0) + 1;
            }
        }

        $kw = trim($keyword);
        $items = [];
        foreach ($this->bootstrap->roles()->all() as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($kw !== ''
                && mb_stripos((string) ($r['name'] ?? ''), $kw) === false
                && mb_stripos((string) ($r['remark'] ?? ''), $kw) === false
                && mb_stripos($id, $kw) === false) {
                continue;
            }
            $items[] = [
                'id'        => $id,
                'type'      => RbacRoles::PRESET_KEYS[$id] ?? '',
                'name'      => (string) ($r['name'] ?? ''),
                'remark'    => (string) ($r['remark'] ?? ''),
                'readonly'  => !empty($r['readonly']),
                'userCount' => (int) ($userCounts[$id] ?? 0),
            ];
        }
        return $items;
    }

    /** 角色管理主页：渲染空壳，数据由 wd-* 组件经 JSON 接口加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('role/index');
    }

    /** JSON：角色列表（workdesktop-ai 的 data-grid 数据源）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $keyword = trim((string) $this->request->get('keyword', ''));
        $items   = $this->roleRows($keyword);
        return $this->jsonOk(['list' => $items, 'total' => count($items)]);
    }

    /**
     * JSON：角色成员选择数据源（全部用户 + 该角色已关联用户 id，供 data-grid 勾选）。
     *
     * data-grid 会把 apiParam 以 param[...] 嵌套传参，这里兼容嵌套与平铺两种写法。
     */
    public function users()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $param  = $this->request->get('param', []);
        $param  = is_array($param) ? $param : [];
        $roleId = (string) ($param['role_id'] ?? $this->request->get('role_id', ''));
        $keyword = trim((string) ($param['keyword'] ?? $this->request->get('keyword', '')));
        $onlySel = $param['only_selected'] ?? $this->request->get('only_selected', '');
        $onlySelected = in_array($onlySel, [true, 1, '1', 'true', 'on'], true);

        $this->bootstrap->roles()->ensureDefaultRoles();
        if ($roleId !== '' && $this->bootstrap->roles()->get($roleId) === null) {
            return $this->jsonFail('角色不存在');
        }

        $users    = $this->bootstrap->rbacUsers();
        $list     = [];
        $selected = [];
        $kw       = $keyword;
        foreach ($users->all() as $u) {
            $uid = (string) ($u['id'] ?? '');
            $has = $roleId !== '' && in_array($roleId, $users->rolesOf($uid), true);
            if ($has) {
                $selected[] = $uid;
            }
            if ($onlySelected && !$has) {
                continue;
            }
            $name  = (string) ($u['name'] ?? '');
            $refId = (string) ($u['ref_id'] ?? '');
            if ($kw !== '' && mb_stripos($name, $kw) === false && mb_stripos($refId, $kw) === false) {
                continue;
            }
            $list[] = [
                'id'      => $uid,
                'name'    => $name,
                'refId'   => $refId,
                'checked' => $has,
            ];
        }

        $total    = count($list);
        $page     = max(1, (int) $this->request->get('currentPage', 1));
        $pageSize = (int) $this->request->get('pageSize', 20);
        $pageSize = $pageSize > 0 ? min($pageSize, 500) : 20;
        // 筛选后页码可能超界（如停在高页码时输入搜索词），回退到最后一页
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page     = min($page, $lastPage);
        $list     = array_slice($list, ($page - 1) * $pageSize, $pageSize);

        return $this->jsonOk([
            'list'        => $list,
            'total'       => $total,
            'selected'    => $selected,
            'currentPage' => $page,
            'pageSize'    => $pageSize,
        ]);
    }

    /** JSON：整体替换角色成员（勾选即加入该角色，取消勾选即移出该角色）。 */
    public function usersSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id  = (string) $this->request->post('id', '');
        $ids = $this->request->post('users', []);
        // 兼容逗号分隔字符串与数组两种传参，避免静默保存 0 名
        $ids = is_array($ids)
            ? array_values(array_unique(array_filter(array_map('strval', $ids))))
            : array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $ids)))));
        if ($id === '') {
            return $this->jsonFail('缺少角色 id');
        }

        $roles = $this->bootstrap->roles();
        $roles->ensureDefaultRoles();
        $role = $roles->get($id);
        if ($role === null) {
            return $this->jsonFail('角色不存在');
        }
        if (!isset(RbacRoles::PRESET_ROLES[$id])) {
            return $this->jsonFail('仅允许调整预设角色的成员');
        }
        // 角色资料只读，但成员绑定对所有预设角色均可调整（含平台占位的超级管理员）

        try {
            $users = $this->bootstrap->rbacUsers();
            foreach ($users->all() as $u) {
                $uid = (string) ($u['id'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $current = $users->rolesOf($uid);
                $has     = in_array($id, $current, true);
                $want    = in_array($uid, $ids, true);
                if ($want && !$has) {
                    $current[] = $id;
                    $users->setRoles($uid, $current);
                } elseif (!$want && $has) {
                    $users->setRoles($uid, array_values(array_diff($current, [$id])));
                }
            }
            $this->bootstrap->auditTrail()->log('role.users', $id, (string) $identity->name, ['count' => count($ids)]);
            return $this->jsonOk(['id' => $id, 'count' => count($ids)], '成员已保存（' . count($ids) . ' 名）');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }
}
