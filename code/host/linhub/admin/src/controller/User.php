<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;

/**
 * 用户管理 GUI（G4 / T5.4 / B3 / J1 / J5）—— admin 应用
 *
 *  - GET  /admin/user/index           本地用户列表（空壳，数据由 wd-* 组件经 JSON 接口加载）
 *  - GET  /admin/user/rows            用户列表（workdesktop-ai 的 data-grid 数据源）
 *  - GET  /admin/user/options         分配抽屉选项（角色 / 权限组 / 权限码标题映射）
 *  - POST /admin/user/save            新增(create) / 编辑(update)
 *  - POST /admin/user/assign          分配角色 / 权限组（多对多）
 *  - POST /admin/user/delete          删除本地映射（确认）
 *  - POST /admin/user/sync            手动全量同步 UAIS 用户（超管；建档/改名/统计，不覆盖授权，不刷登录时间）
 *
 * 本地用户映射 refId 源自 UAIS（手工录入或手动同步建档，见 O-1）。
 */
class User extends AdminController
{
    /**
     * 用户列表数据：名称 / refId / 角色标签 / 权限组数 / 最终权限预览。
     *
     * @param string $keyword 可选检索词，按姓名 / refId 不区分大小写匹配
     */
    private function userRows(string $keyword = ''): array
    {
        $users  = $this->bootstrap->rbacUsers();
        $roles  = $this->bootstrap->roles();
        $groups = $this->bootstrap->permissionGroups();
        $calc   = $this->bootstrap->permissionCalculator();

        $roleMap = [];
        foreach ($roles->all() as $r) {
            $roleMap[(string) ($r['id'] ?? '')] = (string) ($r['name'] ?? '');
        }
        $groupMap = [];
        foreach ($groups->all() as $g) {
            $groupMap[(string) ($g['id'] ?? '')] = (string) ($g['name'] ?? '');
        }

        $items = [];
        foreach ($users->all() as $u) {
            $kw = trim($keyword);
            if ($kw !== '' && mb_stripos((string) ($u['name'] ?? ''), $kw) === false && mb_stripos((string) ($u['ref_id'] ?? ''), $kw) === false) {
                continue;
            }
            $uid = (string) ($u['id'] ?? '');
            $roles = [];
            foreach ($users->rolesOf($uid) as $rid) {
                $rid = (string) $rid;
                $roles[] = ['id' => $rid, 'name' => (string) ($roleMap[$rid] ?? $rid)];
            }
            $groups = [];
            foreach ($users->groupsOf($uid) as $gid) {
                $gid = (string) $gid;
                $groups[] = ['id' => $gid, 'name' => (string) ($groupMap[$gid] ?? $gid)];
            }
            $items[] = [
                'id'          => $uid,
                'name'        => (string) ($u['name'] ?? ''),
                'refId'       => (string) ($u['ref_id'] ?? ''),
                // V1.1：UAIS 同步字段快照（只读展示，来源 UAIS listUsers）
                'username'    => (string) ($u['username'] ?? ''),
                'email'       => (string) ($u['email'] ?? ''),
                'phone'       => (string) ($u['phone'] ?? ''),
                'avatar'      => (string) ($u['avatar'] ?? ''),
                'department'  => (string) ($u['department'] ?? ''),
                'company'     => (string) ($u['company'] ?? ''),
                'title'       => (string) ($u['title'] ?? ''),
                'userType'    => (string) ($u['user_type'] ?? ''),
                'status'      => (string) ($u['status'] ?? ''),
                'tags'        => array_values(array_map('strval', (array) ($u['tags'] ?? []))),
                // 本地可编辑字段
                'remark'      => (string) ($u['remark'] ?? ''),
                'lastLoginAt' => (string) ($u['last_login_at'] ?? ''),
                'roles'       => $roles,
                'groups'      => $groups,
                'permCodes'   => $calc->calcPermissions($uid),
            ];
        }
        return $items;
    }

    /** 用户主页：渲染空壳，数据由 wd-* 组件经 JSON 接口加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('user/index');
    }

    /** JSON：用户列表（workdesktop-ai 的 data-grid 数据源）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $keyword   = trim((string) $this->request->get('keyword', ''));
        $page      = max(1, (int) $this->request->get('currentPage', 1));
        $pageSize  = max(1, (int) $this->request->get('pageSize', 20));
        $all       = $this->userRows($keyword);
        $total     = count($all);
        $list      = array_slice($all, ($page - 1) * $pageSize, $pageSize);
        return $this->jsonOk(['list' => $list, 'total' => $total]);
    }

    /** JSON：分配抽屉选项（角色 / 权限组 / 权限码标题映射）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $roleOptions = [];
        foreach ($this->bootstrap->roles()->all() as $r) {
            $roleOptions[] = ['id' => (string) ($r['id'] ?? ''), 'name' => (string) ($r['name'] ?? '')];
        }
        $groupOptions = [];
        foreach ($this->bootstrap->permissionGroups()->all() as $g) {
            $groupOptions[] = ['id' => (string) ($g['id'] ?? ''), 'name' => (string) ($g['name'] ?? '')];
        }
        // 权限码→标题 映射，供预览展示可读标题
        $permTitles = [];
        foreach ($this->bootstrap->permissions()->all() as $p) {
            $permTitles[(string) ($p['code'] ?? '')] = (string) ($p['title'] ?? ($p['code'] ?? ''));
        }

        return $this->jsonOk([
            'roleOptions'  => $roleOptions,
            'groupOptions' => $groupOptions,
            'permTitles'   => $permTitles,
        ]);
    }

    /** 新增 / 编辑本地用户，记审计 user.save。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $mode  = (string) $this->request->post('mode', 'create');
        $id    = (string) $this->request->post('id', '');
        $name  = trim((string) $this->request->post('name', ''));
        $refId = trim((string) $this->request->post('refId', ''));

        if ($name === '' || $refId === '') {
            return $this->jsonFail('姓名与 UAIS refId 均不能为空');
        }

        $users = $this->bootstrap->rbacUsers();
        // refId 同库唯一（编辑时排除自身）
        foreach ($users->all() as $u) {
            if ((string) ($u['ref_id'] ?? '') === $refId && (string) ($u['id'] ?? '') !== $id) {
                return $this->jsonFail('refId 已存在，请使用唯一标识');
            }
        }

        try {
            // V1.1：本地可编辑字段白名单（UAIS 同步字段只读，不接受本地改）
            $editableKeys = ['remark'];
            $profilePatch = [];
            foreach ($editableKeys as $k) {
                $v = $this->request->post($k, null);
                if ($v !== null) {
                    $profilePatch[$k] = trim((string) $v);
                }
            }

            if ($mode === 'update') {
                if (!$users->update($id, $name)) {
                    throw new \RuntimeException('用户不存在');
                }
                if ($profilePatch !== []) {
                    $users->patchProfile($id, $profilePatch);
                }
                $target = $id;
            } else {
                $target = $users->create($name, $refId, [], [], null, $profilePatch);
            }
            $this->bootstrap->auditTrail()->log('user.save', (string) $target, (string) $identity->name, [
                'mode' => $mode, 'name' => $name, 'refId' => $refId, 'profile' => $profilePatch,
            ]);
            return $this->jsonOk(['id' => $target], $mode === 'update' ? '用户已更新' : '用户已创建');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 分配角色 / 权限组（多对多整体替换），记审计 user.assign。 */
    public function assign()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id        = (string) $this->request->post('id', '');
        $roleIds   = $this->splitList($this->request->post('roles', []));
        $groupIds  = $this->splitList($this->request->post('groups', []));

        if ($id === '') {
            return $this->jsonFail('缺少用户 id');
        }

        try {
            $users = $this->bootstrap->rbacUsers();
            // 校验角色/权限组存在，杜绝越权挂接失效引用
            $roles   = array_map(fn ($r) => (string) ($r['id'] ?? ''), $this->bootstrap->roles()->all());
            $groups  = array_map(fn ($g) => (string) ($g['id'] ?? ''), $this->bootstrap->permissionGroups()->all());
            $badRoles  = array_values(array_diff($roleIds, $roles));
            $badGroups = array_values(array_diff($groupIds, $groups));
            if ($badRoles !== [] || $badGroups !== []) {
                return $this->jsonFail('含失效角色/权限组引用');
            }
            $users->setRoles($id, $roleIds);
            $users->setGroups($id, $groupIds);
            $this->bootstrap->auditTrail()->log('user.assign', $id, (string) $identity->name, ['roles' => $roleIds, 'groups' => $groupIds]);
            return $this->jsonOk(['id' => $id], '角色与权限组已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 删除本地用户映射（确认，不影响 UAIS），记审计 user.delete。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $id = (string) $this->request->post('id', '');
        if ($id === '') {
            return $this->jsonFail('缺少用户 id');
        }

        try {
            $users = $this->bootstrap->rbacUsers();
            $name  = (string) (($users->get($id)['name'] ?? ''));
            if (!$users->delete($id)) {
                throw new \RuntimeException('用户不存在');
            }
            $this->bootstrap->auditTrail()->log('user.delete', $id, (string) $identity->name, ['name' => $name]);
            return $this->jsonOk(['id' => $id], '本地用户映射已删除（不影响 UAIS 账号）');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * 手动同步 UAIS 用户（超管），记审计 user.sync。
     * 支持三种策略（mode，白名单校验，缺省 diff_overwrite）：
     *   diff_add        差异添加：仅对本地缺失的启用账号建档；已存在完全不动
     *   diff_overwrite  差异覆盖：只同步 UAIS 返回的账号；系统单独添加的不动；不删除
     *   overwrite       完全覆盖：UAIS 为唯一权威全量对齐；删除本地多余 + 禁用置本地禁用
     * 三种模式均不覆盖本地角色/权限组授权，也不刷新登录时间。
     *
     * @return \think\response\Json
     */
    public function sync()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $mode = (string) $this->request->post('mode', 'diff_overwrite');
        if (!in_array($mode, ['diff_add', 'diff_overwrite', 'overwrite'], true)) {
            return $this->jsonFail('未知同步策略: ' . $mode);
        }

        try {
            $stats = $this->bootstrap->syncRemoteUsers($mode);
            $this->bootstrap->auditTrail()->log('user.sync', '', (string) $identity->name, $stats);
            return $this->jsonOk($stats, '同步完成');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 归一化多选提交：数组（多数浏览器）或逗号串（重复键被 PHP 只留最后一个时）。 */
    private function splitList(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map('strval', $list))));
    }
}