<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use RuntimeException;

/**
 * 角色管理（T5.2 / J2）
 *
 * 角色类型：工作者 / 功能管理员 / 系统管理员 / 超级管理员。
 * 角色 CRUD（创建工作者/功能管理员/系统管理员；超级管理员由平台占位、只读）。
 * 角色【不绑定】权限（T5.6 / J3）：角色记录本身不含权限字段。
 */
final class RbacRoles
{
    /** 角色类型常量 */
    public const WORKER          = 'WORKER';
    public const FUNCTIONAL_ADMIN = 'FUNCTIONAL_ADMIN';
    public const SYSTEM_ADMIN     = 'SYSTEM_ADMIN';
    public const SUPER_ADMIN      = 'SUPER_ADMIN';

    /** 类型显示名 */
    public const TYPE_NAMES = [
        self::WORKER           => '工作者',
        self::FUNCTIONAL_ADMIN => '功能管理员',
        self::SYSTEM_ADMIN     => '系统管理员',
        self::SUPER_ADMIN      => '超级管理员',
    ];

    private RbacStorage $store;

    public function __construct(RbacStorage $store)
    {
        $this->store = $store;
    }

    /**
     * 初始化/补齐四种默认角色。超级管理员为平台占位（只读），其余可用于 CRUD。
     */
    public function ensureDefaultRoles(): void
    {
        $roles = $this->store->section(RbacStorage::ROLES);
        $changed = false;
        foreach (self::TYPE_NAMES as $type => $name) {
            $exists = false;
            foreach ($roles as $r) {
                if ((string) ($r['type'] ?? '') === $type) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $id = 'role_' . strtolower($type);
                $roles[$id] = [
                    'id'       => $id,
                    'type'     => $type,
                    'name'     => $name,
                    'readonly' => $type === self::SUPER_ADMIN,
                ];
                $changed = true;
            }
        }
        if ($changed) {
            $this->store->putSection(RbacStorage::ROLES, $roles);
        }
    }

    /**
     * 创建角色。超级管理员为平台占位类型，禁止自行创建（抛出异常）。
     *
     * @throws RuntimeException 当 type 为 SUPER_ADMIN 时
     */
    public function create(string $type, string $name, ?string $id = null): string
    {
        if ($type === self::SUPER_ADMIN) {
            throw new RuntimeException('超级管理员角色由平台占位，不可自行创建');
        }
        if (!isset(self::TYPE_NAMES[$type])) {
            throw new RuntimeException("非法角色类型：{$type}");
        }
        $rid = $id ?: 'role_' . substr(md5((string) microtime(true)), 0, 10);
        $this->store->put(RbacStorage::ROLES, $rid, [
            'id'       => $rid,
            'type'     => $type,
            'name'     => $name,
            'readonly' => false,
        ]);
        return $rid;
    }

    /** 更新角色名称。超级管理员只读。 */
    public function update(string $id, string $name): bool
    {
        $role = $this->store->find(RbacStorage::ROLES, $id);
        if ($role === null) {
            return false;
        }
        if (!empty($role['readonly'])) {
            throw new RuntimeException('只读角色不可修改');
        }
        $this->store->put(RbacStorage::ROLES, $id, ['id' => $id, 'type' => $role['type'], 'name' => $name, 'readonly' => false]);
        return true;
    }

    /** 删除角色。超级管理员与系统管理员占位只读，不可删。 */
    public function delete(string $id): bool
    {
        $role = $this->store->find(RbacStorage::ROLES, $id);
        if ($role === null) {
            return false;
        }
        if (!empty($role['readonly'])) {
            throw new RuntimeException('只读角色不可删除');
        }
        $this->store->remove(RbacStorage::ROLES, $id);

        // 清理用户在用户角色关联中的引用
        $ur = $this->store->section(RbacStorage::USER_ROLES);
        foreach ($ur as $uid => $list) {
            $ur[$uid] = array_values(array_filter($list, fn ($r) => (string) $r !== $id));
        }
        $this->store->putSection(RbacStorage::USER_ROLES, $ur);
        return true;
    }

    /** 单角色查询。 */
    public function get(string $id): ?array
    {
        return $this->store->find(RbacStorage::ROLES, $id);
    }

    /** 全部角色。 */
    public function all(): array
    {
        return array_values($this->store->section(RbacStorage::ROLES));
    }
}