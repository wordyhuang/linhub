<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use RuntimeException;

/**
 * 用户管理（T5.4 / B3 / J1 / J5）
 *
 * 用户维护 + 分配角色（可多）+ 分配权限组（可多）。多对多关系：
 * 用户↔角色、用户↔权限组 均为多对多，关联存储于用户级关联分区。
 */
final class RbacUser
{
    private RbacStorage $store;

    public function __construct(RbacStorage $store)
    {
        $this->store = $store;
    }

    /** 创建用户。 */
    public function create(string $name, string $refId, array $roles = [], array $groups = [], ?string $id = null, array $profile = []): string
    {
        $uid = $id ?: 'user_' . substr(md5((string) microtime(true)), 0, 10);
        $row = [
            'id'     => $uid,
            'name'   => $name,
            'ref_id' => $refId, // 关联 UAIS / 外部系统用户标识
        ];
        // V1.1：合并 UAIS 同步过来的扩展字段（email/phone/department/company/avatar/tags/user_type/status 等）
        foreach ($this->profileKeys() as $key) {
            if (array_key_exists($key, $profile)) {
                $row[$key] = $profile[$key];
            }
        }
        $this->store->put(RbacStorage::USERS, $uid, $row);
        $this->store->putSection(RbacStorage::USER_ROLES, $this->store->section(RbacStorage::USER_ROLES) + [$uid => array_unique($roles)]);
        $this->store->putSection(RbacStorage::USER_GROUPS, $this->store->section(RbacStorage::USER_GROUPS) + [$uid => array_unique($groups)]);
        return $uid;
    }

    /** 更新用户资料（保留运行时字段 + 扩展字段）。 */
    public function update(string $id, string $name): bool
    {
        $user = $this->store->find(RbacStorage::USERS, $id);
        if ($user === null) {
            return false;
        }
        // V1.1：原地保留除 name/ref_id 外的所有字段（含 last_login_at 与 UAIS 同步来的 profile 字段）
        $user['name'] = $name;
        $this->store->put(RbacStorage::USERS, $id, $user);
        return true;
    }

    /**
     * 合并用户档案字段（patch）。UAIS 同步字段与本地可编辑字段共用此方法。
     *
     * @param string $id   本地用户 id
     * @param array  $data 任意 profile 字段（email/phone/department/company/avatar/user_type/status/tags/remark 等）
     * @return bool 用户存在且更新成功返回 true
     */
    public function patchProfile(string $id, array $data): bool
    {
        $user = $this->store->find(RbacStorage::USERS, $id);
        if ($user === null) {
            return false;
        }
        foreach ($data as $k => $v) {
            if (in_array($k, $this->profileKeys(), true)) {
                $user[$k] = $v;
            }
        }
        $this->store->put(RbacStorage::USERS, $id, $user);
        return true;
    }

    /** 支持的扩展字段白名单（UAIS 同步字段 + 本地可编辑字段）。 */
    private function profileKeys(): array
    {
        return [
            'username', 'email', 'phone', 'avatar', 'gender', 'department', 'company', 'title',
            'user_type', 'status', 'tags', 'remark', 'last_login_at',
        ];
    }

    /**
     * 记录一次登录：更新最后登录时间（Y-m-d H:i:s）。
     *
     * @param string      $id 本地用户 id
     * @param string|null $at 指定时间（测试/外部时钟注入）；null 取服务器当前时间
     * @return bool 用户存在且更新成功返回 true；用户不存在返回 false
     */
    public function touchLogin(string $id, ?string $at = null): bool
    {
        $user = $this->store->find(RbacStorage::USERS, $id);
        if ($user === null) {
            return false;
        }
        $user['last_login_at'] = $at ?? date('Y-m-d H:i:s');
        $this->store->put(RbacStorage::USERS, $id, $user);
        return true;
    }

    /** 删除用户（连同关联清理）。 */
    public function delete(string $id): bool
    {
        if ($this->store->find(RbacStorage::USERS, $id) === null) {
            return false;
        }
        $this->store->remove(RbacStorage::USERS, $id);
        $ur = $this->store->section(RbacStorage::USER_ROLES);
        unset($ur[$id]);
        $this->store->putSection(RbacStorage::USER_ROLES, $ur);
        $ug = $this->store->section(RbacStorage::USER_GROUPS);
        unset($ug[$id]);
        $this->store->putSection(RbacStorage::USER_GROUPS, $ug);
        return true;
    }

    /** 查询用户。 */
    public function get(string $id): ?array
    {
        return $this->store->find(RbacStorage::USERS, $id);
    }

    /** 全部用户。 */
    public function all(): array
    {
        return array_values($this->store->section(RbacStorage::USERS));
    }

    /** 按外部标识（ref_id，关联 UAIS / 外部系统用户标识）反查本地用户；无匹配返回 null。 */
    public function findByRefId(string $refId): ?array
    {
        if ($refId === '') {
            return null;
        }
        foreach ($this->store->section(RbacStorage::USERS) as $user) {
            if ((string) ($user['ref_id'] ?? '') === $refId) {
                return $user;
            }
        }
        return null;
    }

    /** 取用户已分配的角色 ID 列表。 */
    public function rolesOf(string $userId): array
    {
        return array_values((array) ($this->store->section(RbacStorage::USER_ROLES)[$userId] ?? []));
    }

    /** 设置用户的角色集（整体替换）。 */
    public function setRoles(string $userId, array $roleIds): void
    {
        $this->assertUser($userId);
        $ur = $this->store->section(RbacStorage::USER_ROLES);
        $ur[$userId] = array_values(array_unique($roleIds));
        $this->store->putSection(RbacStorage::USER_ROLES, $ur);
    }

    /** 追加一个角色（多角色）。 */
    public function assignRole(string $userId, string $roleId): void
    {
        $roles = $this->rolesOf($userId);
        if (!in_array($roleId, $roles, true)) {
            $roles[] = $roleId;
        }
        $this->setRoles($userId, $roles);
    }

    /** 取用户已分配的权限组 ID 列表。 */
    public function groupsOf(string $userId): array
    {
        return array_values((array) ($this->store->section(RbacStorage::USER_GROUPS)[$userId] ?? []));
    }

    /** 设置用户的权限组集（整体替换）。 */
    public function setGroups(string $userId, array $groupIds): void
    {
        $this->assertUser($userId);
        $ug = $this->store->section(RbacStorage::USER_GROUPS);
        $ug[$userId] = array_values(array_unique($groupIds));
        $this->store->putSection(RbacStorage::USER_GROUPS, $ug);
    }

    /** 追加一个权限组（多权限组）。 */
    public function assignGroup(string $userId, string $groupId): void
    {
        $groups = $this->groupsOf($userId);
        if (!in_array($groupId, $groups, true)) {
            $groups[] = $groupId;
        }
        $this->setGroups($userId, $groups);
    }

    private function assertUser(string $userId): void
    {
        if ($this->store->find(RbacStorage::USERS, $userId) === null) {
            throw new RuntimeException("用户不存在：{$userId}");
        }
    }
}