<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 工作区首页快捷入口声明登记（shortcuts.php）
 *
 * 每个功能包（含平台内置）通过包根 shortcuts.php 声明首页「功能入口卡片」：
 *  - default  安装后默认展示的卡片，用户可在「定制」中删除；每个包最多 3 个
 *  - optional 备选卡片，用户可在「定制」抽屉中自行添加；每个包最多 20 个
 *
 * 卡片以菜单 id 引用（标题/路由/图标/权限均取自菜单），故本注册表只保存 id 与分组，
 * 避免与 menus.php 重复维护展示属性。超限时截断并产生告警，不阻断安装。
 */
final class ShortcutRegistry
{
    /** 分区键 */
    private const SECTION = 'shortcuts';

    /** 默认显示卡片上限（每包）。 */
    public const MAX_DEFAULT = 3;

    /** 备选卡片上限（每包）。 */
    public const MAX_OPTIONAL = 20;

    private IntegrationRegistry $store;

    public function __construct(IntegrationRegistry $store)
    {
        $this->store = $store;
    }

    /**
     * 登记一个包的快捷入口声明（分区覆盖写入）。
     *
     * @param array{default?: array, optional?: array} $defs shortcuts.php 返回的声明
     * @return list<string> 告警（超限截断等）
     */
    public function register(string $short, array $defs): array
    {
        $warnings = [];
        $default  = $this->normalize($defs['default'] ?? [], self::MAX_DEFAULT, '默认显示', $short, $warnings);
        $optional = $this->normalize($defs['optional'] ?? [], self::MAX_OPTIONAL, '备选', $short, $warnings);

        // 同一卡片不得同时出现在两组：默认优先
        $optional = array_values(array_diff($optional, $default));

        $this->store->save($short, [self::SECTION => ['default' => $default, 'optional' => $optional]]);
        return $warnings;
    }

    /**
     * 归一化一组卡片声明：支持「菜单 id 字符串」或「含 id 键的数组」两种写法，
     * 去空、去重、超限截断。
     *
     * @param mixed        $items 声明原文
     * @param list<string> $warnings 告警收集
     * @return list<string>
     */
    private function normalize($items, int $max, string $label, string $short, array &$warnings): array
    {
        $out = [];
        foreach ((array) $items as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : (string) $item;
            if ($id === '' || in_array($id, $out, true)) {
                continue;
            }
            $out[] = $id;
        }
        if (count($out) > $max) {
            $warnings[] = "功能包 {$short} 的{$label}快捷入口超过上限 {$max}，超出部分已忽略";
            $out = array_slice($out, 0, $max);
        }
        return $out;
    }

    /**
     * 某包的快捷入口声明；未登记返回空结构。
     *
     * @return array{default: list<string>, optional: list<string>}
     */
    public function get(string $short): array
    {
        $v = (array) (($this->store->get($short) ?? [])[self::SECTION] ?? []);
        return [
            'default'  => array_values((array) ($v['default'] ?? [])),
            'optional' => array_values((array) ($v['optional'] ?? [])),
        ];
    }

    /**
     * 某包的默认显示卡片 id 列表（安装/升级时写入用户定制用）。
     *
     * @return list<string>
     */
    public function defaultIds(string $short): array
    {
        return $this->get($short)['default'];
    }

    /**
     * 全部已登记快捷入口，按包登记顺序、组内声明顺序（默认组在前）。
     *
     * @return list<array{id: string, group: string, short: string}>
     */
    public function allEntries(): array
    {
        $out = [];
        foreach ($this->store->read() as $short => $sections) {
            if ($short === IntegrationRegistry::GLOBAL) {
                continue;
            }
            $sc = (array) ($sections[self::SECTION] ?? []);
            foreach (['default', 'optional'] as $group) {
                foreach ((array) ($sc[$group] ?? []) as $id) {
                    $id = (string) $id;
                    if ($id !== '') {
                        $out[] = ['id' => $id, 'group' => $group, 'short' => (string) $short];
                    }
                }
            }
        }
        return $out;
    }
}
