<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 菜单接入（T4.2 / A5 / J6）
 *
 * 依据 menus.php 解析并注入宿主导航。菜单带 perms 过滤声明（缺省不限），供 S5 RBAC 过滤消费。
 * 无效 route（指向不存在的控制器动作）记警告并跳过（valid=false），不影响包其他装配。
 */
final class MenuRegistry
{
    /** 分区键 */
    private const SECTION = 'menus';

    private IntegrationRegistry $store;

    public function __construct(IntegrationRegistry $store)
    {
        $this->store = $store;
    }

    /**
     * 登记一个功能包的菜单声明。
     *
     * @param array<int, array<string, mixed>> $defs menus.php 返回的菜单项数组
     * @param callable|null $routeExists 校验 route 对应的控制器是否存在；null 视为全有效
     * @return list<string> 警告（无效 route 被跳过）
     */
    public function register(string $short, array $defs, ?callable $routeExists = null): array
    {
        $warnings = [];
        $list = [];
        foreach ($defs as $d) {
            $id = (string) ($d['id'] ?? '');
            if ($id === '') {
                $warnings[] = "菜单缺少 id，已跳过（包：{$short}）";
                continue;
            }
            $route = (string) ($d['route'] ?? '');
            $valid = $route === '' || $routeExists === null || (bool) $routeExists($route);
            $item = [
                'id'     => $id,
                'parent' => $d['parent'] ?? null,
                'title'  => (string) ($d['title'] ?? ''),
                'group'  => (string) ($d['group'] ?? ''),
                'route'  => $route,
                'icon'   => (string) ($d['icon'] ?? ''),
                'order'  => (int) ($d['order'] ?? 0),
                'perms'  => array_values((array) ($d['perms'] ?? [])), // 缺省不限
                'valid'  => $valid,
                'short'  => $short,
            ];
            if (!$valid) {
                $item['warn'] = "无效 route，已跳过：{$route}";
                $warnings[] = $item['warn'];
            }
            $list[] = $item;
        }
        $this->store->save($short, [self::SECTION => $list]);
        return $warnings;
    }

    /**
     * 有效菜单（注入宿主导航的集合，供渲染与 RBAC 过滤）。
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->store->read() as $short => $sections) {
            if ($short === IntegrationRegistry::GLOBAL) {
                continue;
            }
            foreach ((array) ($sections[self::SECTION] ?? []) as $item) {
                if (!empty($item['valid'])) {
                    $out[] = $item;
                }
            }
        }
        return $out;
    }

    /** 按 id 取一个有效菜单。 */
    public function get(string $id): ?array
    {
        foreach ($this->all() as $m) {
            if (($m['id'] ?? '') === $id) {
                return $m;
            }
        }
        return null;
    }

    /**
     * 被跳过（无效 route）的菜单，供告警/审计查看。
     *
     * @return list<array<string, mixed>>
     */
    public function skipped(): array
    {
        $out = [];
        foreach ($this->store->read() as $short => $sections) {
            if ($short === IntegrationRegistry::GLOBAL) {
                continue;
            }
            foreach ((array) ($sections[self::SECTION] ?? []) as $item) {
                if (empty($item['valid'])) {
                    $out[] = $item;
                }
            }
        }
        return $out;
    }
}