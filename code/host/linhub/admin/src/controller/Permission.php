<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\rbac\RbacRoles;

/**
 * 权限项浏览（G3，A4/J7/J8）—— 只读页，无任何写路由。
 *
 *  - GET /admin/permission/index   权限点只读列表（空壳，配 wd-* 组件）
 *  - GET /admin/permission/rows    权限点只读列表 JSON + 角色筛选下拉
 *
 * 数据源：HostBootstrap->permissions()（S4 PermissionRegistry.all()）。
 * 可见性：roles 空=不限角色（J7-b）；非空=与所选角色有交集才可见（J7）。
 */
class Permission extends AdminController
{
    /** 权限点列表 JSON（含角色筛选下拉）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $items = array_map(static function (array $p): array {
            $roles = array_values((array) ($p['roles'] ?? []));
            return [
                'code'        => (string) ($p['code'] ?? ''),
                'title'       => (string) ($p['title'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'group'       => (string) ($p['group'] ?? ''),
                'short'       => (string) ($p['short'] ?? ''),
                'open'        => $roles === [],
                'roles'       => $roles,
            ];
        }, $this->bootstrap->permissions()->all());

        // 角色筛选（J7/J7-b）：roles 空=不限；非空=与所选角色有交集才可见
        $role = trim((string) $this->request->get('role', ''));
        if ($role !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool => $p['open'] || in_array($role, $p['roles'], true)));
        }

        // 筛选下拉：四种角色类型（G1 数据源，值用显示名以便与 roles 声明匹配）
        $roleOptions = [];
        foreach (RbacRoles::TYPE_NAMES as $type => $name) {
            $roleOptions[] = ['type' => $type, 'name' => $name];
        }

        return $this->jsonOk(['list' => $items, 'total' => count($items), 'roleOptions' => $roleOptions]);
    }

    /** JSON：角色筛选下拉。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $roleOptions = [];
        foreach (RbacRoles::TYPE_NAMES as $type => $name) {
            $roleOptions[] = ['type' => $type, 'name' => $name];
        }
        return $this->jsonOk(['roleOptions' => $roleOptions]);
    }

    /** 权限点只读页：渲染空壳，数据由 wd-* 组件经 JSON 接口加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('permission/index');
    }
}