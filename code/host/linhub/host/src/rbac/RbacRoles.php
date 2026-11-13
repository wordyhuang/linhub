<?php

declare(strict_types=1);

/*
 * 文档创建时间：2026-10-20
 * 最后修改时间：2026-11-11
 * 当前版本：V2.1
 * 适用对象：宿主 RBAC 消费方（HostBootstrap / admin 控制器 / auth 服务）
 * 核心目标：维护 5 个系统预设角色（工作者/审核员/功能管理员/系统管理员/超级管理员），角色不可增删改
 * 文档简略描述：V2.0 收缩角色模型——取消角色类型维度，仅保留预设角色；ensureDefaultRoles 负责
 *               补齐预设并物理清除存量自定义角色（含用户绑定引用清理）；角色【不绑定】权限（T5.6 / J3）。
 *               V2.1 成员口径：角色资料只读；所有预设角色（含平台占位的超级管理员）成员均可调整，readonly 仅标记平台占位。
 */

namespace linhub\host\rbac;

/**
 * 角色管理（T5.2 / J2）—— V2.0 仅预设角色。
 *
 * 系统只允许 5 个预设角色，角色 id 固定（role_<英文小写>），角色资料只读、不可增删改；
 * readonly 仅标记平台占位（超级管理员），成员绑定对所有预设角色均可调整；
 * 角色记录不再含 type 字段（角色类型维度已取消），角色键即 {@see self::PRESET_ROLES} 的键。
 */
final class RbacRoles
{
    /** 预设角色英文键（与权限项 roles 字段、插槽 roles、优先级表同一口径） */
    public const WORKER          = 'WORKER';
    public const REVIEWER        = 'REVIEWER';
    public const FUNCTIONAL_ADMIN = 'FUNCTIONAL_ADMIN';
    public const SYSTEM_ADMIN     = 'SYSTEM_ADMIN';
    public const SUPER_ADMIN      = 'SUPER_ADMIN';

    /**
     * 预设角色：固定 id → 中文名（顺序即展示顺序）。
     *
     * @var array<string, string>
     */
    public const PRESET_ROLES = [
        'role_worker'           => '工作者',
        'role_reviewer'         => '审核员',
        'role_functional_admin' => '功能管理员',
        'role_system_admin'     => '系统管理员',
        'role_super_admin'      => '超级管理员',
    ];

    /** 预设角色 id → 英文键（roleTypesFor / 权限可见性 / 插槽过滤共用口径）。 */
    public const PRESET_KEYS = [
        'role_worker'           => self::WORKER,
        'role_reviewer'         => self::REVIEWER,
        'role_functional_admin' => self::FUNCTIONAL_ADMIN,
        'role_system_admin'     => self::SYSTEM_ADMIN,
        'role_super_admin'      => self::SUPER_ADMIN,
    ];

    private RbacStorage $store;

    public function __construct(RbacStorage $store)
    {
        $this->store = $store;
    }

    /**
     * 补齐 5 个预设角色并物理清除非预设角色。
     *
     * 预设角色记录形如 ['id','name','remark','readonly']（无 type 字段）；
     * readonly 仅标记平台占位（超级管理员），成员绑定对所有预设角色均可调整；
     * 清除自定义角色时同步清理用户-角色绑定中的悬空引用。
     */
    public function ensureDefaultRoles(): void
    {
        $roles = $this->store->section(RbacStorage::ROLES);
        $changed = false;

        // 1) 补齐预设角色（已有则保留名称/备注，缺则创建）
        foreach (self::PRESET_ROLES as $id => $name) {
            // 平台占位口径：readonly 仅标记超级管理员（资料由平台维护），成员一律可调
            $placeholder = ($id === 'role_super_admin');
            if (!isset($roles[$id]) || !is_array($roles[$id])) {
                $roles[$id] = ['id' => $id, 'name' => $name, 'readonly' => $placeholder];
                $changed = true;
                continue;
            }
            // 兼容旧记录：剔除 type 字段，readonly 按是否平台占位对齐（矫正历史全 true 的存量）
            $row = $roles[$id];
            if (array_key_exists('type', $row) || (bool) ($row['readonly'] ?? false) !== $placeholder) {
                $roles[$id] = [
                    'id'       => $id,
                    'name'     => (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : $name,
                    'remark'   => (string) ($row['remark'] ?? ''),
                    'readonly' => $placeholder,
                ];
                $changed = true;
            }
        }

        // 2) 物理清除非预设角色
        foreach (array_keys($roles) as $id) {
            if (!isset(self::PRESET_ROLES[$id])) {
                unset($roles[$id]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->store->putSection(RbacStorage::ROLES, $roles);
        }

        // 3) 清理用户-角色绑定中的悬空引用
        $ur = $this->store->section(RbacStorage::USER_ROLES);
        $urChanged = false;
        foreach ($ur as $uid => $list) {
            $filtered = array_values(array_filter(
                (array) $list,
                fn ($r) => isset(self::PRESET_ROLES[(string) $r])
            ));
            if ($filtered !== (array) $list) {
                $ur[$uid] = $filtered;
                $urChanged = true;
            }
        }
        if ($urChanged) {
            $this->store->putSection(RbacStorage::USER_ROLES, $ur);
        }
    }

    /** 单角色查询。 */
    public function get(string $id): ?array
    {
        return $this->store->find(RbacStorage::ROLES, $id);
    }

    /** 全部角色（ensureDefaultRoles 后仅含预设角色）。 */
    public function all(): array
    {
        return array_values($this->store->section(RbacStorage::ROLES));
    }
}
