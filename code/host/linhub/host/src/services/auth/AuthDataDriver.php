<?php

declare(strict_types=1);

namespace linhub\host\services\auth;

use linhub\host\rbac\RbacRoles;
use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 账号与权限服务 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1 / 8）
 *
 * V1.2（2026-11-10）角色模型收缩：系统仅 5 个预设角色（工作者/审核员/功能管理员/系统管理员/超级管理员），
 * 全部为平台定义、不可增删改——角色定义不再随功能包导出/导入：
 *  - export：恒返回空集（预设角色全部 readonly，不随包交付）；
 *  - import：仅接受 5 个预设角色 id 的绑定信息且不创建任何角色，非预设 id 记告警并跳过；
 *  - purge 恒返回 0（卸载不删除角色）。
 * 权限点由宿主 PermissionRegistry 代码定义，随宿主代码交付，不导出；
 * 用户、用户-角色绑定、用户组一律不导出。
 * scope 语义：seed / all 一致（均返回空集）；range 不支持。
 */
final class AuthDataDriver implements ServiceDataDriver
{
    public function __construct(private readonly PlatformStore $store)
    {
    }

    public function capabilities(): array
    {
        return ['scopes' => ['seed', 'all'], 'has_runtime' => false];
    }

    public function export(string $packageKey, string $scope, ?array $range, int $limit): array
    {
        // V1.2：角色全部为平台预设定义，不随功能包导出，恒返回空集
        return ['rows' => [], 'truncated' => false];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        // V1.2：仅接受 5 个预设角色 id 的绑定信息且不创建任何角色；
        // 非预设 id 记告警并跳过（角色模型收缩后，数据包不应再携带角色定义）
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $roleId = trim((string) ($row['role_id'] ?? ''));
            if (!isset(RbacRoles::PRESET_ROLES[$roleId])) {
                @error_log('[auth] import 跳过非预设角色：' . $roleId);
                continue;
            }
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        // 角色无包归属字段，卸载不得删除平台公共角色数据
        return 0;
    }
}
