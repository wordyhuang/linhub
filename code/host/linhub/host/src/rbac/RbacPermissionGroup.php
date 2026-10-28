<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use RuntimeException;

/**
 * 权限组管理（T5.3 / J4）
 *
 * 权限组 CRUD + 挂接任意权限项（权限项 code 列表）。权限组与权限项为多对多关系：
 * 同一权限组可挂多个权限项；同一权限项可被挂到多个权限组（由权限组集合上存储实现）。
 */
final class RbacPermissionGroup
{
    private RbacStorage $store;

    public function __construct(RbacStorage $store)
    {
        $this->store = $store;
    }

    /** 创建权限组（可同时挂接权限项与针对角色）。 */
    public function create(string $name, array $permCodes = [], array $roleTypes = [], ?string $id = null): string
    {
        $gid = $id ?: 'group_' . substr(md5((string) microtime(true)), 0, 10);
        $this->store->put(RbacStorage::GROUPS, $gid, [
            'id'    => $gid,
            'name'  => $name,
            'roles' => array_values(array_unique($roleTypes)),
            'perms' => array_values(array_unique($permCodes)),
        ]);
        return $gid;
    }

    /** 更新权限组名称与权限项（整组替换）；$roleTypes 为 null 时沿用现有针对角色。 */
    public function update(string $id, string $name, array $permCodes, ?array $roleTypes = null): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            return false;
        }
        $roles = $roleTypes ?? (array) ($group['roles'] ?? []);
        $this->store->put(RbacStorage::GROUPS, $id, [
            'id'    => $id,
            'name'  => $name,
            'roles' => array_values(array_unique($roles)),
            'perms' => array_values(array_unique($permCodes)),
        ]);
        return true;
    }

    /** 只改名称（针对角色沿用）。 */
    public function rename(string $id, string $name): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            return false;
        }
        $this->store->put(RbacStorage::GROUPS, $id, [
            'id'    => $id,
            'name'  => $name,
            'roles' => (array) ($group['roles'] ?? []),
            'perms' => (array) ($group['perms'] ?? []),
        ]);
        return true;
    }

    /** 设置权限组针对的角色类型（整体替换），记审计交由调用方。 */
    public function setRoles(string $id, array $roleTypes): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            throw new RuntimeException("权限组不存在：{$id}");
        }
        return $this->update($id, (string) ($group['name'] ?? ''), (array) ($group['perms'] ?? []), $roleTypes);
    }

    /** 取权限组针对的角色类型列表。 */
    public function rolesOf(string $id): array
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        return array_values((array) ($group['roles'] ?? []));
    }

    /** 清空权限组内容。 */
    public function setPerms(string $id, array $permCodes): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            throw new RuntimeException("权限组不存在：{$id}");
        }
        return $this->update($id, (string) ($group['name'] ?? ''), $permCodes);
    }

    /** 追加一个权限项。 */
    public function addPermission(string $id, string $code): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            return false;
        }
        $perms = (array) ($group['perms'] ?? []);
        if (!in_array($code, $perms, true)) {
            $perms[] = $code;
        }
        return $this->update($id, (string) ($group['name'] ?? ''), $perms);
    }

    /** 移除一个权限项。 */
    public function removePermission(string $id, string $code): bool
    {
        $group = $this->store->find(RbacStorage::GROUPS, $id);
        if ($group === null) {
            return false;
        }
        $perms = array_values(array_filter((array) ($group['perms'] ?? []), fn ($c) => (string) $c !== $code));
        return $this->update($id, (string) ($group['name'] ?? ''), $perms);
    }

    /** 删除权限组。 */
    public function delete(string $id): bool
    {
        if ($this->store->find(RbacStorage::GROUPS, $id) === null) {
            return false;
        }
        $this->store->remove(RbacStorage::GROUPS, $id);

        // 清理用户在用户-权限组关联中的引用
        $ug = $this->store->section(RbacStorage::USER_GROUPS);
        foreach ($ug as $uid => $list) {
            $ug[$uid] = array_values(array_filter($list, fn ($g) => (string) $g !== $id));
        }
        $this->store->putSection(RbacStorage::USER_GROUPS, $ug);
        return true;
    }

    /** 查询权限组（含 perms 列表）。 */
    public function get(string $id): ?array
    {
        return $this->store->find(RbacStorage::GROUPS, $id);
    }

    /** 取该权限组下的用户 id 列表（反向索引 USER_GROUPS）。 */
    public function userIdsOf(string $groupId): array
    {
        $ids = [];
        foreach ($this->store->section(RbacStorage::USER_GROUPS) as $uid => $list) {
            if (in_array($groupId, $list, true)) {
                $ids[] = (string) $uid;
            }
        }
        return $ids;
    }

    /** 整体替换该权限组下的用户集（组→用户反向挂接），校验用户存在且组存在。 */
    public function setUsers(string $groupId, array $userIds): void
    {
        if ($this->store->find(RbacStorage::GROUPS, $groupId) === null) {
            throw new RuntimeException("权限组不存在：{$groupId}");
        }
        $userIds = array_values(array_unique(array_map('strval', $userIds)));
        foreach ($userIds as $uid) {
            if ($this->store->find(RbacStorage::USERS, $uid) === null) {
                throw new RuntimeException("用户不存在：{$uid}");
            }
        }

        $ug = $this->store->section(RbacStorage::USER_GROUPS);
        // 先从所有用户中剥离该组，再逐个追加到选中用户
        foreach ($ug as $uid => $list) {
            $ug[$uid] = array_values(array_filter($list, fn ($g) => (string) $g !== $groupId));
        }
        foreach ($userIds as $uid) {
            $cur = array_values((array) ($ug[$uid] ?? []));
            if (!in_array($groupId, $cur, true)) {
                $cur[] = $groupId;
            }
            $ug[$uid] = $cur;
        }
        $this->store->putSection(RbacStorage::USER_GROUPS, $ug);
    }

    /** 全部权限组。 */
    public function all(): array
    {
        return array_values($this->store->section(RbacStorage::GROUPS));
    }
}