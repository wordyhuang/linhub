<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\rbac\PermissionVisibility;
use linhub\host\rbac\RbacRoles;

/**
 * 权限组管理 GUI（G2 / T5.3 / J4 / J7）—— admin 应用
 *
 *  - GET  /admin/group/index              权限组列表（名称 + 权限项数）
 *  - POST /admin/group/save               新增(create) / 改名(rename)
 *  - POST /admin/group/perms              挂接权限项（setPerms，整体替换）
 *  - POST /admin/group/delete             删除（前端确认）
 *
 * 权限项来源 G3 的 PermissionRegistry::all()；挂接保存后端校验权限码须已登记（PermissionVisibility 可判定），
 * 拒绝非法/未登记编码，杜绝越权写入。
 */
class Group extends AdminController
{
    /**
     * 权限组列表数据（含权限项数 + 已挂权限码，供 tooltip/回显）。
     *
     * @param string $keyword 可选检索词，按权限组名不区分大小写匹配
     */
    private function groupRows(string $keyword = ''): array
    {
        $rows = [];
        $groups = $this->bootstrap->permissionGroups();
        $kw = trim($keyword);
        foreach ($groups->all() as $g) {
            if ($kw !== '' && mb_stripos((string) ($g['name'] ?? ''), $kw) === false) {
                continue;
            }
            $gid = (string) ($g['id'] ?? '');
            $rows[] = [
                'id'        => $gid,
                'name'      => (string) ($g['name'] ?? ''),
                'roles'     => array_values((array) ($g['roles'] ?? [])),
                'permCount' => count((array) ($g['perms'] ?? [])),
                'permMap'   => array_values((array) ($g['perms'] ?? [])),
                'userCount' => count($groups->userIdsOf($gid)),
            ];
        }
        return $rows;
    }

    /** 权限点全量 + 自角色可见性（J7/J7-b），供抽屉挂接。 */
    private function permissionsWithVisibility(): array
    {
        $v = PermissionVisibility::fromRegistry($this->bootstrap->permissions());
        $permissions = [];
        foreach ($this->bootstrap->permissions()->all() as $p) {
            $rolesRef = array_values((array) ($p['roles'] ?? []));
            $visibleFor = [];
            foreach (RbacRoles::TYPE_NAMES as $t => $tn) {
                $visibleFor[$t] = $v->isVisible((string) ($p['code'] ?? ''), [$tn]);
            }
            $permissions[] = [
                'code'        => (string) ($p['code'] ?? ''),
                'title'       => (string) ($p['title'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'group'       => (string) ($p['group'] ?? ''),
                'short'      => (string) ($p['short'] ?? ''),
                'open'       => $rolesRef === [],
                'roles'      => $rolesRef,
                'visibleFor' => $visibleFor,
            ];
        }
        return $permissions;
    }

    /** 权限组主页：渲染空壳，数据由 wd-* 组件经 JSON 接口加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('group/index');
    }

    /** JSON：权限组列表（workdesktop-ai 的 data-grid 数据源）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $keyword = trim((string) $this->request->get('keyword', ''));
        $rows    = $this->groupRows($keyword);
        return $this->jsonOk(['list' => $rows, 'total' => count($rows)]);
    }

    /** JSON：权限点全量 + 抽屉顶部角色筛选 + 已挂权限码 map（挂接抽屉初始化数据）。 */
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

        $permMap = [];
        foreach ($this->bootstrap->permissionGroups()->all() as $g) {
            $permMap[(string) ($g['id'] ?? '')] = array_values((array) ($g['perms'] ?? []));
        }

        return $this->jsonOk([
            'permissions' => $this->permissionsWithVisibility(),
            'roleOptions' => $roleOptions,
            'permMap'     => $permMap,
        ]);
    }

    /** 新增 / 改名权限组，记审计 group.save。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $mode = (string) $this->request->post('mode', 'create');
        $id   = (string) $this->request->post('id', '');
        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            return $this->jsonFail('权限组名称不能为空');
        }
        $roles = $this->splitList($this->request->post('roles', []));

        try {
            $groups = $this->bootstrap->permissionGroups();
            if ($mode === 'update') {
                if ($id === '') {
                    throw new \RuntimeException('缺少权限组 id');
                }
                // 沿用现有角色（未传 roles 时）或整体替换
                $curRoles = $roles ?: $groups->rolesOf($id);
                if (!$groups->update($id, $name, $groups->get($id)['perms'] ?? [], $curRoles)) {
                    throw new \RuntimeException('权限组不存在');
                }
                $target = $id;
            } else {
                $target = $groups->create($name, [], $roles);
            }
            $this->bootstrap->auditTrail()->log('group.save', $target, (string) $identity->name, ['mode' => $mode, 'name' => $name]);
            return $this->jsonOk(['id' => $target], $mode === 'update' ? '权限组已改名' : '权限组已创建');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 挂接权限项（整体替换），记审计 group.perms；后端校验权限码均已登记。 */
    public function perms()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id    = (string) $this->request->post('id', '');
        $codes = $this->request->post('codes', []);
        if (!is_array($codes)) {
            $raw = trim((string) $codes);
            $codes = $raw === '' ? [] : explode(',', $raw);
        }
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));

        if ($id === '') {
            return $this->jsonFail('缺少权限组 id');
        }

        $known = [];
        foreach ($this->bootstrap->permissions()->all() as $p) {
            $known[(string) ($p['code'] ?? '')] = true;
        }
        $unknown = array_values(array_filter($codes, fn ($c) => !isset($known[$c])));
        if ($unknown !== []) {
            return $this->jsonFail('含未登记权限码：' . implode(', ', $unknown));
        }

        // 后端校验：挂接码须对该权限组针对角色的并集可见（J7-b 语义）；未设角色视为不限角色
        try {
            $groups = $this->bootstrap->permissionGroups();
            $roleNames = [];
            foreach ($groups->rolesOf($id) as $rt) {
                if (isset(RbacRoles::TYPE_NAMES[$rt])) {
                    $roleNames[] = RbacRoles::TYPE_NAMES[$rt];
                }
            }
            if ($roleNames !== []) {
                $vis = PermissionVisibility::fromRegistry($this->bootstrap->permissions());
                $hidden = array_values(array_filter($codes, fn ($c) => !$vis->isVisible((string) $c, $roleNames)));
                if ($hidden !== []) {
                    return $this->jsonFail('所选权限项对当前权限组角色不可见：' . implode(', ', $hidden));
                }
            }
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }

        try {
            $this->bootstrap->permissionGroups()->setPerms($id, $codes);
            $this->bootstrap->auditTrail()->log('group.perms', $id, (string) $identity->name, ['codes' => $codes]);
            return $this->jsonOk(['id' => $id, 'count' => count($codes)], '权限项已保存（' . count($codes) . ' 项）');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 拆分逗号分隔/数组入参为去重字符串列表。 */
    private function splitList(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map('strval', $list))));
    }

    /** JSON：全量用户列表 + 指定权限组已选用户 id（选人表格数据源）。 */
    public function users()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $groupId = (string) $this->request->get('group_id', '');
        $selected = $groupId === '' ? [] : $this->bootstrap->permissionGroups()->userIdsOf($groupId);

        $list = [];
        foreach ($this->bootstrap->rbacUsers()->all() as $u) {
            $list[] = [
                'id'     => (string) ($u['id'] ?? ''),
                'name'   => (string) ($u['name'] ?? ''),
                'refId'  => (string) ($u['ref_id'] ?? ''),
                'checked'=> in_array((string) ($u['id'] ?? ''), $selected, true),
            ];
        }
        return $this->jsonOk(['list' => $list, 'total' => count($list), 'selected' => $selected]);
    }

    /** 整体替换权限组下的用户集（组→用户反向挂接），记审计 group.users。 */
    public function usersSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id     = (string) $this->request->post('id', '');
        $ids    = $this->request->post('users', []);
        $ids    = is_array($ids) ? array_values(array_map('strval', $ids)) : [];

        if ($id === '') {
            return $this->jsonFail('缺少权限组 id');
        }

        try {
            $this->bootstrap->permissionGroups()->setUsers($id, $ids);
            $this->bootstrap->auditTrail()->log('group.users', $id, (string) $identity->name, ['count' => count($ids)]);
            return $this->jsonOk(['id' => $id, 'count' => count($ids)], '用户已保存（' . count($ids) . ' 名）');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 删除权限组（前端确认），记审计 group.delete。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id = (string) $this->request->post('id', '');
        if ($id === '') {
            return $this->jsonFail('缺少权限组 id');
        }

        try {
            $this->bootstrap->permissionGroups()->delete($id);
            $this->bootstrap->auditTrail()->log('group.delete', $id, (string) $identity->name, []);
            return $this->jsonOk(['id' => $id], '权限组已删除');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }
}