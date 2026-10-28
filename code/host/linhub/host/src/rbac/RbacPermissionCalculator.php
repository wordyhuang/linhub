<?php

declare(strict_types=1);

namespace linhub\host\rbac;

/**
 * 最终权限计算（T5.5 / J6）
 *
 * 用户最终权限 = 其所有权限组内权限项的并集，供鉴权使用。
 * 角色不绑定权限（T5.6），故计算仅来自权限组。
 */
final class RbacPermissionCalculator
{
    private RbacStorage $store;

    public function __construct(RbacStorage $store)
    {
        $this->store = $store;
    }

    /**
     * 计算某用户的最终权限 code 集合。
     *
     * @return list<string> 去重后的权限编码列表
     */
    public function calcPermissions(string $userId): array
    {
        $groupIds  = $this->store->section(RbacStorage::USER_GROUPS)[$userId] ?? [];
        $groups    = $this->store->section(RbacStorage::GROUPS);
        $permSet   = [];
        foreach ($groupIds as $gid) {
            $perms = (array) (($groups[(string) $gid] ?? [])['perms'] ?? []);
            foreach ($perms as $code) {
                $permSet[(string) $code] = true;
            }
        }
        return array_keys($permSet);
    }
}