<?php

declare(strict_types=1);

/*
 * 文档创建时间：2026-10-20
 * 最后修改时间：2026-10-21
 * 当前版本：V1.1
 * 适用对象：宿主服务治理（ServiceInjector）
 * 核心目标：RbacQuery 契约实现
 * 文档简略描述：聚合 HostBootstrap 现有 RBAC 数据源（rbacUsers/roles/permissionGroups/permissions/permissionCalculator）。除 ensureRole 外全部方法 try/catch 兜底返回空结构、功能包侧不抛异常；ensureRole 为写方法（无则创建、有则跳过），失败返回 error 而非静默吞错。
 */

namespace linhub\host\services\auth;

use linhub\host\rbac\RbacRoles;
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

    public function ensureRole(string $name, string $type): array
    {
        $name = trim($name);
        $type = strtoupper(trim($type));
        if ($name === '') {
            return ['created' => false, 'id' => '', 'name' => '', 'type' => $type, 'error' => '角色名称不能为空'];
        }
        if (!isset(RbacRoles::TYPE_NAMES[$type])) {
            return ['created' => false, 'id' => '', 'name' => $name, 'type' => $type, 'error' => "非法角色类型：{$type}"];
        }
        if ($type === RbacRoles::SUPER_ADMIN) {
            return ['created' => false, 'id' => '', 'name' => $name, 'type' => $type, 'error' => '超级管理员角色由平台占位，不可接入'];
        }
        try {
            $roles = $this->boot()->roles();
            $roles->ensureDefaultRoles();
            foreach ($roles->all() as $r) {
                if (mb_strtolower((string) ($r['name'] ?? '')) === mb_strtolower($name)) {
                    return [
                        'created' => false,
                        'id'      => (string) ($r['id'] ?? ''),
                        'name'    => (string) ($r['name'] ?? ''),
                        'type'    => (string) ($r['type'] ?? $type),
                        'error'   => '',
                    ];
                }
            }
            $rid = $roles->create($type, $name);
            return ['created' => true, 'id' => $rid, 'name' => $name, 'type' => $type, 'error' => ''];
        } catch (\Throwable $e) {
            // 写失败如实返回 error，不伪装成"已跳过"，避免功能包因角色缺失而静默降级
            return ['created' => false, 'id' => '', 'name' => $name, 'type' => $type, 'error' => $e->getMessage()];
        }
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
            $uid = $this->currentUserId();
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
            $uid = $this->currentUserId();
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
            return array_values(array_map('strval', $this->boot()->permissionCalculator()->calcPermissions($userId)));
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
                $type = (string) ($r['type'] ?? '');
                $id = (string) ($r['id'] ?? '');
                $out[] = [
                    'id'        => $id,
                    'name'      => (string) ($r['name'] ?? ''),
                    'type'      => $type,
                    'typeName'  => (string) (RbacRoles::TYPE_NAMES[$type] ?? $type),
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
                return ['id' => '', 'name' => '', 'type' => '', 'typeName' => '', 'readonly' => false, 'userCount' => 0];
            }
            $type = (string) ($r['type'] ?? '');
            return [
                'id'        => (string) ($r['id'] ?? ''),
                'name'      => (string) ($r['name'] ?? ''),
                'type'      => $type,
                'typeName'  => (string) (RbacRoles::TYPE_NAMES[$type] ?? $type),
                'readonly'   => !empty($r['readonly']),
                'userCount' => count($this->userIdsOfRole((string) ($r['id'] ?? ''))),
            ];
        } catch (\Throwable) {
            return ['id' => '', 'name' => '', 'type' => '', 'typeName' => '', 'readonly' => false, 'userCount' => 0];
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
                    'permCodes' => array_values(array_map('strval', (array) ($g['permissions'] ?? $g['perm_codes'] ?? []))),
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
                'permCodes' => array_values(array_map('strval', (array) ($g['permissions'] ?? $g['perm_codes'] ?? []))),
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
