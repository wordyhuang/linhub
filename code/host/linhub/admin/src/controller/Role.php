<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\rbac\RbacRoles;

/**
 * 角色管理 GUI（G1 / T5.2 / J2 / J3）—— admin 应用
 *
 *  - GET  /admin/role/index    角色列表（空壳，配 wd-* 组件）
 *  - GET  /admin/role/rows     角色列表 JSON（四默认角色 + 每角色用户数）
 *  - GET  /admin/role/options  新增下拉的角色类型 JSON
 *  - POST /admin/role/save     新增(create) / 改名(update)，JSON
 *  - POST /admin/role/delete   删除（前端确认，后端只读角色拒绝），JSON
 *
 * 角色仅承载类型标签语义（J2），【不】绑定权限（J3），页面不提供挂权限入口。
 * type 下拉仅允许 TYPE_NAMES 四枚举，后端二次白名单校验（含禁止自建超级管理员）。
 * 每个写操作成功路径写 AuditTrail（action=role.save / role.delete）。
 */
class Role extends AdminController
{
    /**
     * 角色列表：四默认角色 + 每角色用户数。
     *
     * @param string $keyword 可选检索词，按角色名 / 类型名不区分大小写匹配
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
            $type = (string) ($r['type'] ?? '');
            $id   = (string) ($r['id'] ?? '');
            $typeName = (string) (RbacRoles::TYPE_NAMES[$type] ?? $type);
            if ($kw !== ''
                && mb_stripos((string) ($r['name'] ?? ''), $kw) === false
                && mb_stripos($typeName, $kw) === false
                && mb_stripos($type, $kw) === false) {
                continue;
            }
            $items[] = [
                'id'        => $id,
                'name'      => (string) ($r['name'] ?? ''),
                'type'      => $type,
                'typeName'  => $typeName,
                'readonly'  => !empty($r['readonly']),
                'userCount' => (int) ($userCounts[$id] ?? 0),
            ];
        }
        return $items;
    }

    /** 新增下拉仅允许可创建的角色类型（排除 SUPER_ADMIN 占位）。 */
    private function createOptions(): array
    {
        $createOptions = [];
        foreach (RbacRoles::TYPE_NAMES as $type => $name) {
            if ($type === RbacRoles::SUPER_ADMIN) {
                continue;
            }
            $createOptions[] = ['type' => $type, 'name' => $name];
        }
        return $createOptions;
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
     * JSON：指定角色下的成员用户列表（按角色 id 反查，供"查看成员"抽屉）。
     *
     * @param string $keyword 可选检索词，按用户姓名 / refId 过滤
     */
    public function members()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $roleId  = (string) $this->request->get('role_id', '');
        $keyword = trim((string) $this->request->get('keyword', ''));
        if ($roleId === '') {
            return $this->jsonFail('缺少角色 id');
        }
        // 角色须存在（兼容只读占位角色读取）
        $this->bootstrap->roles()->ensureDefaultRoles();
        $role = $this->bootstrap->roles()->get($roleId);
        if ($role === null) {
            return $this->jsonFail('角色不存在');
        }

        $kw    = $keyword;
        $users = [];
        foreach ($this->bootstrap->rbacUsers()->all() as $u) {
            $uid = (string) ($u['id'] ?? '');
            if (!in_array($roleId, $this->bootstrap->rbacUsers()->rolesOf($uid), true)) {
                continue;
            }
            $name  = (string) ($u['name'] ?? '');
            $refId = (string) ($u['ref_id'] ?? '');
            if ($kw !== '' && mb_stripos($name, $kw) === false && mb_stripos($refId, $kw) === false) {
                continue;
            }
            $users[] = ['id' => $uid, 'name' => $name, 'refId' => $refId];
        }
        return $this->jsonOk(['role' => ['id' => $roleId, 'name' => (string) ($role['name'] ?? '')], 'list' => $users, 'total' => count($users)]);
    }

    /** JSON：新增下拉角色类型（排除超级管理员占位）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk(['createOptions' => $this->createOptions()]);
    }

    /** 新增 / 改名：type 白名单二次校验，成功记审计 role.save（JSON）。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $mode = (string) $this->request->post('mode', 'create');
        $id   = (string) $this->request->post('id', '');
        $type = (string) $this->request->post('type', '');
        $name = trim((string) $this->request->post('name', ''));

        if ($name === '') {
            return $this->jsonFail('角色名称不能为空');
        }

        $roles = $this->bootstrap->roles();
        try {
            if ($mode === 'update') {
                if ($id === '') {
                    throw new \RuntimeException('缺少角色 id');
                }
                $roles->update($id, $name);
                $target = $id;
            } else {
                // 后端白名单：仅允许 J2 四枚举且禁止自建超级管理员
                if ($type === '' || !isset(RbacRoles::TYPE_NAMES[$type])) {
                    throw new \RuntimeException('非法角色类型：' . $type);
                }
                if ($type === RbacRoles::SUPER_ADMIN) {
                    throw new \RuntimeException('超级管理员由平台占位，不可自行创建');
                }
                $target = $roles->create($type, $name);
            }
            $this->bootstrap->auditTrail()->log('role.save', $target, (string) $identity->name, [
                'mode' => $mode,
                'type' => $type,
                'name' => $name,
            ]);
            return $this->jsonOk(['id' => $target], $mode === 'update' ? '角色已改名' : '角色已创建');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 删除角色（前端确认；后端只读角色拒绝），成功记审计 role.delete（JSON）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id = (string) $this->request->post('id', '');
        if ($id === '') {
            return $this->jsonFail('缺少角色 id');
        }

        try {
            $this->bootstrap->roles()->delete($id);
            $this->bootstrap->auditTrail()->log('role.delete', $id, (string) $identity->name, []);
            return $this->jsonOk(['id' => $id], '角色已删除');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }
}