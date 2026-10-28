<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use linhub\host\integration\PermissionRegistry;

/**
 * 权限可见性（T5.6 / J7 / J3）
 *
 * 基于 S4 权限声明（permissions.php）的 roles 判定权限对当前角色/用户是否可见。
 * 权限项 roles 省略或为空 => 对所有角色可见；roles 非空 => 仅当与用户角色有交集才可见。
 * 角色不绑定权限（J3）：可见性只依据权限声明，角色本身无权限字段。
 *
 * 权限来源：默认自洽接收权限点数组；提供 {@see self::fromRegistry()} 复用 S4 PermissionRegistry.all() 作为权限源。
 */
final class PermissionVisibility
{
    /** @var list<array<string, mixed>> 权限点声明集合（每项含 code/roles 等） */
    private array $permissions;

    /**
     * @param list<array<string, mixed>> $permissions 权限点数组
     */
    public function __construct(array $permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * 从 S4 权限登记复用权限点作为权限源。
     */
    public static function fromRegistry(PermissionRegistry $registry): self
    {
        return new self($registry->all());
    }

    /**
     * 某权限对给定角色集合是否可见。
     *
     * @param list<string> $roles 用户当前角色（类型名，如 WORKER/功能管理员）
     */
    public function isVisible(string $code, array $roles): bool
    {
        foreach ($this->permissions as $p) {
            if ((string) ($p['code'] ?? '') !== $code) {
                continue;
            }
            $openRoles = array_values((array) ($p['roles'] ?? []));
            // 缺省不限角色（J7-b）
            if ($openRoles === []) {
                return true;
            }
            return count(array_intersect($openRoles, $roles)) > 0;
        }
        return false;
    }

    /**
     * 对给定角色可见的全部权限点。
     *
     * @param list<string> $roles
     * @return list<array<string, mixed>>
     */
    public function visibleTo(array $roles): array
    {
        return array_values(array_filter(
            $this->permissions,
            fn (array $p): bool => $this->isVisible((string) ($p['code'] ?? ''), $roles),
        ));
    }
}