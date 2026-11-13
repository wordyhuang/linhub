<?php

declare(strict_types=1);

/*
 * 文档创建时间：2026-10-20
 * 最后修改时间：2026-11-10
 * 当前版本：V1.2
 * 适用对象：宿主服务治理（ServiceInjector）
 * 核心目标：RbacQuery 契约实现
 * 文档简略描述：聚合 HostBootstrap 现有 RBAC 数据源（rbacUsers/roles/permissionGroups/permissions/permissionCalculator）。
 *               V1.2 收缩：角色仅限 5 个系统预设角色且不可增删改，移除 ensureRole 写方法；全部方法 try/catch 兜底返回空结构、功能包侧不抛异常。
 */

namespace linhub\host\services\auth;

use linhub\host\support\HostBootstrap;

/**
 * RbacQuery 实现（AQ-1 ~ AQ-10）。
 *
 * 数据源：HostBootstrap 进程级单例；构造时可选注入 bootstrap（测试用）。
 * 安全：所有公共方法都被 try/catch 包住，任何底层异常都被吞掉返回空结构，
 *       功能包侧永远不会因 RBAC 服务异常而 500。
 */
final class RbacQueryService implements RbacQuery
{
    private ?HostBootstrap $bootstrap;

    public function __construct(?HostBootstrap $bootstrap = null)
    {
        $this->bootstrap = $bootstrap;
    }

    /** 惰性获取 bootstrap（无参友好构造，对齐 LoginStateService 范式）。 */
    private function boot(): HostBootstrap
    {
        return $this->bootstrap ??= new HostBootstrap();
    }

    public function currentSnapshot(): array
    {
        try {
            $boot = $this->boot();
            $identity = $boot->currentUser();
            if ($identity === null) {
                return [
                    'is_login'       => false,
                    'guest'          => true,
                    'user_id'        => '',
                    'username'        => '',
                    'display_name'    => '',
                    'is_super_admin' => false,
                    'tags'           => [],
                    'roles'          => [],
                ];
            }
            return [
                'is_login'       => true,
                'guest'          => false,
                'user_id'        => (string) $identity->id,
                'username'       => (string) $identity->name,
                'display_name'   => (string) $identity->name,
                'is_super_admin' => $boot->authService()->isSuperAdmin($identity),
                'tags'           => array_values(array_map('strval', $identity->tags)),
                'roles'          => $boot->roleTypesFor($identity),
            ];
        } catch (\Throwable) {
            return [
                'is_login'       => false,
                'guest'          => true,
                'user_id'        => '',
                'username'       => '',
                'display_name'    => '',
                'is_super_admin' => false,
                'tags'           => [],
                'roles'          => [],
            ];
        }
    }

    public function currentUserId(): string
    {
        return (string) ($this->currentSnapshot()['user_id'] ?? '');
    }

    public function currentPermCodes(): array
    {
        $uid = $this->currentUserId();
        if ($uid === '') {
            return [];
        }
        return $this->permCodesOf($uid);
    }

    public function currentRoleIds(): array
    {
        try {
            $uid = $this->resolveUserId($this->currentUserId());
            if ($uid === '') {
                return [];
            }
            return array_values(array_map('strval', $this->boot()->rbacUsers()->rolesOf($uid)));
        } catch (\Throwable) {
            return [];
        }
    }

    public function currentGroupIds(): array
    {
        try {
            $uid = $this->resolveUserId($this->currentUserId());
            if ($uid === '') {
                return [];
            }
            return array_values(array_map('strval', $this->boot()->rbacUsers()->groupsOf($uid)));
        } catch (\Throwable) {
            return [];
        }
    }

    public function userById(string $userId): array
    {
        try {
            $boot = $this->boot();
            $u = $boot->rbacUsers()->get($userId);
            if ($u === null) {
                return $this->emptyUser();
            }
            $uid = (string) ($u['id'] ?? '');
            return [
                'id'          => $uid,
                'name'        => (string) ($u['name'] ?? ''),
                'refId'       => (string) ($u['ref_id'] ?? ''),
                // V1.1：UAIS 同步字段透传给功能包
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
                'remark'      => (string) ($u['remark'] ?? ''),
                'lastLoginAt' => (string) ($u['last_login_at'] ?? ''),
                'roles'       => array_values(array_map('strval', $boot->rbacUsers()->rolesOf($uid))),
                'groups'      => array_values(array_map('strval', $boot->rbacUsers()->groupsOf($uid))),
                'permCodes'   => array_values(array_map('strval', $boot->permissionCalculator()->calcPermissions($uid))),
            ];
        } catch (\Throwable) {
            return $this->emptyUser();
        }
    }

    public function userByRefId(string $refId): array
    {
        try {
            $boot = $this->boot();
            $u = $boot->rbacUsers()->findByRefId($refId);
            if ($u === null) {
                return $this->emptyUser();
            }
            return $this->userById((string) ($u['id'] ?? ''));
        } catch (\Throwable) {
            return $this->emptyUser();
        }
    }

    public function listUsers(string $keyword = ''): array
    {
        try {
            $boot = $this->boot();
            $kw = trim($keyword);
            $out = [];
            foreach ($boot->rbacUsers()->all() as $u) {
                $name = (string) ($u['name'] ?? '');
                $refId = (string) ($u['ref_id'] ?? '');
                if ($kw !== '' && mb_stripos($name, $kw) === false && mb_stripos($refId, $kw) === false) {
                    continue;
                }
                $out[] = [
                    'id'          => (string) ($u['id'] ?? ''),
                    'name'        => $name,
                    'refId'       => $refId,
                    'lastLoginAt' => (string) ($u['last_login_at'] ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function permCodesOf(string $userId): array
    {
        try {
            $local = $this->resolveUserId($userId);
            if ($local === '') {
                return [];
            }
            return array_values(array_map('strval', $this->boot()->permissionCalculator()->calcPermissions($local)));
        } catch (\Throwable) {
            return [];
        }
    }

    public function listRoles(): array
    {
        try {
            $boot = $this->boot();
            $boot->roles()->ensureDefaultRoles();
            $userCounts = [];
            foreach ($boot->rbacUsers()->all() as $u) {
                $uid = (string) ($u['id'] ?? '');
                foreach ($boot->rbacUsers()->rolesOf($uid) as $rid) {
                    $rid = (string) $rid;
                    $userCounts[$rid] = ($userCounts[$rid] ?? 0) + 1;
                }
            }
            $out = [];
            foreach ($boot->roles()->all() as $r) {
                $id = (string) ($r['id'] ?? '');
                $out[] = [
                    'id'        => $id,
                    'name'      => (string) ($r['name'] ?? ''),
                    'readonly'  => !empty($r['readonly']),
                    'userCount' => (int) ($userCounts[$id] ?? 0),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function roleById(string $roleId): array
    {
        try {
            $boot = $this->boot();
            $boot->roles()->ensureDefaultRoles();
            $r = $boot->roles()->get($roleId);
            if ($r === null) {
                return ['id' => '', 'name' => '', 'readonly' => false, 'userCount' => 0];
            }
            return [
                'id'        => (string) ($r['id'] ?? ''),
                'name'      => (string) ($r['name'] ?? ''),
                'readonly'   => !empty($r['readonly']),
                'userCount' => count($this->userIdsOfRole((string) ($r['id'] ?? ''))),
            ];
        } catch (\Throwable) {
            return ['id' => '', 'name' => '', 'readonly' => false, 'userCount' => 0];
        }
    }

    public function userIdsOfRole(string $roleId): array
    {
        try {
            $boot = $this->boot();
            $out = [];
            foreach ($boot->rbacUsers()->all() as $u) {
                $uid = (string) ($u['id'] ?? '');
                if (in_array($roleId, $boot->rbacUsers()->rolesOf($uid), true)) {
                    $out[] = $uid;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function listGroups(): array
    {
        try {
            $out = [];
            foreach ($this->boot()->permissionGroups()->all() as $g) {
                $out[] = [
                    'id'        => (string) ($g['id'] ?? ''),
                    'name'      => (string) ($g['name'] ?? ''),
                    'permCodes' => array_values(array_map('strval', (array) ($g['perms'] ?? $g['permissions'] ?? $g['perm_codes'] ?? []))),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function groupById(string $groupId): array
    {
        try {
            $g = $this->boot()->permissionGroups()->get($groupId);
            if ($g === null) {
                return ['id' => '', 'name' => '', 'permCodes' => []];
            }
            return [
                'id'        => (string) ($g['id'] ?? ''),
                'name'      => (string) ($g['name'] ?? ''),
                'permCodes' => array_values(array_map('strval', (array) ($g['perms'] ?? $g['permissions'] ?? $g['perm_codes'] ?? []))),
            ];
        } catch (\Throwable) {
            return ['id' => '', 'name' => '', 'permCodes' => []];
        }
    }

    public function listPermissions(): array
    {
        try {
            $out = [];
            foreach ($this->boot()->permissions()->all() as $p) {
                $roles = array_values(array_map('strval', (array) ($p['roles'] ?? [])));
                $out[] = [
                    'code'        => (string) ($p['code'] ?? ''),
                    'title'       => (string) ($p['title'] ?? ''),
                    'description' => (string) ($p['description'] ?? ''),
                    'group'       => (string) ($p['group'] ?? ''),
                    'short'       => (string) ($p['short'] ?? ''),
                    'open'        => $roles === [],
                    'roles'       => $roles,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function hasPermission(string $code): bool
    {
        try {
            return $this->boot()->permissions()->get($code) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function userHasPermission(string $userId, string $code): bool
    {
        try {
            return in_array($code, $this->permCodesOf($userId), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** 把 UAIS 身份 id 或本地 RBAC 用户 id 统一解析为本地用户 id；解析不到返回空串。 */
    private function resolveUserId(string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        $users = $this->boot()->rbacUsers();
        if ($users->get($userId) !== null) {
            return $userId;
        }
        $u = $users->findByRefId($userId);
        return (string) ($u['id'] ?? '');
    }

    /** 空用户档案占位（字段与 userById 返回一致）。 */
    private function emptyUser(): array
    {
        return [
            'id'          => '',
            'name'        => '',
            'refId'       => '',
            'username'    => '',
            'email'       => '',
            'phone'       => '',
            'avatar'      => '',
            'department'  => '',
            'company'     => '',
            'title'       => '',
            'userType'    => '',
            'status'      => '',
            'tags'        => [],
            'remark'      => '',
            'lastLoginAt' => '',
            'roles'       => [],
            'groups'      => [],
            'permCodes'   => [],
        ];
    }
}
