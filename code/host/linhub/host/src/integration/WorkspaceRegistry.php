<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 角色专属工作台 + 内容插槽（T4.3 / K1 / K2，T4.4 / T5 / K）
 *
 * - 每角色默认工作台页面（K1），宿主管控，按角色存储。
 * - 功能包通过 workspace.php 声明向工作台插入的内容块（K2）。
 * - 插入内容按用户最终权限过滤显示（T4.4）：声明时登记 perms，S5 消费时按其过滤；
 *   预留权限回调槽位（permChecker），未设置时默认按"声明 perms 与用户权限有交集则显示"。
 */
final class WorkspaceRegistry
{
    /** 分区键 */
    private const SECTION = 'workspace';

    private IntegrationRegistry $store;
    /** @var callable|null 权限回调槽位(S5)：function(array $declaredPerms, array $userPerms): bool */
    private $permChecker;

    public function __construct(IntegrationRegistry $store, ?callable $permChecker = null)
    {
        $this->store = $store;
        $this->permChecker = $permChecker;
    }

    /** 设置（或更新）某角色的默认工作台页面（K1）。 */
    public function setDefault(string $role, string $page): void
    {
        $global = $this->store->get(IntegrationRegistry::GLOBAL) ?? [];
        $defaults = (array) ($global['workspace_defaults'] ?? []);
        $defaults[$role] = $page;
        $this->store->save(IntegrationRegistry::GLOBAL, ['workspace_defaults' => $defaults]);
    }

    /** 读取某角色的默认工作台页面；未设置返回 null。 */
    public function defaultFor(string $role): ?string
    {
        $global = $this->store->get(IntegrationRegistry::GLOBAL) ?? [];
        $v = $global['workspace_defaults'][$role] ?? '';
        return $v === '' ? null : (string) $v;
    }

    /**
     * 登记一个功能包的工作台内容插槽（K2）。
     *
     * @param array<int, array<string, mixed>> $slots workspace.php 返回的插槽数组
     * @return int 登记的插槽数
     */
    public function registerSlots(string $short, array $slots): int
    {
        $list = [];
        foreach ($slots as $s) {
            $list[] = [
                'id'     => (string) ($s['id'] ?? ''),
                'title'  => (string) ($s['title'] ?? ''),
                'render' => (string) ($s['render'] ?? ''),
                // 固定位置锚点：缺省落到功能入口上方的 home.top；order 升序排列
                'zone'   => (string) (($s['zone'] ?? '') ?: 'home.top'),
                'order'  => (int) ($s['order'] ?? 0),
                'perms'  => array_values((array) ($s['perms'] ?? [])), // 缺省不限
                'short'  => $short,
            ];
        }
        $this->store->save($short, [self::SECTION => ['slots' => $list]]);
        return count($list);
    }

    /**
     * 全部已登记的工作台插槽（合并各包）。
     *
     * @return list<array<string, mixed>>
     */
    public function allSlots(): array
    {
        $out = [];
        foreach ($this->store->read() as $short => $sections) {
            if ($short === IntegrationRegistry::GLOBAL) {
                continue;
            }
            foreach ((array) ($sections[self::SECTION]['slots'] ?? []) as $slot) {
                $out[] = $slot;
            }
        }
        return $out;
    }

    /**
     * 按用户最终权限过滤可见插槽（T4.4 / T5）。
     * 插槽 perms 为空 => 恒可见；否则：若设置了权限回调则按回调判定，否则按"声明的任一 perms 命中用户权限"判定。
     *
     * @param list<string> $userPerms 用户最终权限（S5 计算后传入，本处仅消费）
     * @return list<array<string, mixed>>
     */
    public function slotsAllowedBy(array $userPerms): array
    {
        return array_values(array_filter(
            $this->allSlots(),
            function (array $slot) use ($userPerms): bool {
                $perms = (array) ($slot['perms'] ?? []);
                if ($perms === []) {
                    return true;
                }
                if ($this->permChecker !== null) {
                    return (bool) ($this->permChecker)($perms, $userPerms);
                }
                return count(array_intersect($perms, $userPerms)) > 0;
            },
        ));
    }

    /** 设置权限回调槽位（S5 消费处注入）；传 null 恢复默认判定。 */
    public function setPermissionChecker(?callable $checker): void
    {
        $this->permChecker = $checker;
    }
}