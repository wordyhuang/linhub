<?php

declare(strict_types=1);

namespace linhub\host\home;

use linhub\host\rbac\JsonFileStore;

/**
 * 角色首页个人定制存储（首页入口可定制）。
 *
 * 持久化每个用户在各角色首页上选择展示的菜单（含功能包菜单）有序 id 列表。
 * 结构：{userId: {homeKey: [menuId, ...]}}。
 *  - 未定制（键不存在）：返回 null，由页面使用默认入口集合；
 *  - 显式保存空数组：返回 []，表示用户选择不显示任何入口；
 * 二者语义不同，故 get 对未定制返回 null。
 */
final class HomeCustomization
{
    private JsonFileStore $store;

    public function __construct(string $file)
    {
        $this->store = new JsonFileStore($file);
    }

    /**
     * 读取某用户某角色首页的定制菜单 id 列表。
     *
     * @return list<string>|null 未定制返回 null；定制（含空列表）返回有序 id 列表
     */
    public function get(string $userId, string $homeKey): ?array
    {
        $v = $this->store->read()[$userId][$homeKey] ?? null;
        if ($v === null) {
            return null;
        }
        return array_values(array_map('strval', (array) $v));
    }

    /**
     * 保存（覆盖）某用户某角色首页的定制菜单 id 列表；自动去重并保持首次出现顺序。
     *
     * @param list<string> $menuIds
     */
    public function save(string $userId, string $homeKey, array $menuIds): void
    {
        $data = $this->store->read();
        $data[$userId][$homeKey] = $this->uniqueIds($menuIds);
        $this->store->write($data);
    }

    /** 重置（删除）某用户某角色首页的定制，使其回到默认入口。 */
    public function reset(string $userId, string $homeKey): void
    {
        $data = $this->store->read();
        unset($data[$userId][$homeKey]);
        if (isset($data[$userId]) && $data[$userId] === []) {
            unset($data[$userId]);
        }
        $this->store->write($data);
    }

    /**
     * 把新装/升级功能包的默认显示卡片追加到所有已定制列表末尾。
     *
     * 仅处理已定制（键存在）的用户首页：未定制用户的默认集合在渲染时自动包含新声明，
     * 无需落盘。已存在的同 id 不重复追加。
     *
     * @param list<string> $menuIds
     */
    public function appendDefaults(array $menuIds): void
    {
        $ids = $this->uniqueIds($menuIds);
        $data = $this->store->read();
        if ($ids === [] || $data === []) {
            return;
        }
        foreach ($data as $userId => $homes) {
            foreach ((array) $homes as $homeKey => $list) {
                $data[$userId][$homeKey] = $this->uniqueIds(array_merge((array) $list, $ids));
            }
        }
        $this->store->write($data);
    }

    /**
     * 去重并保持顺序。
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function uniqueIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_values($out);
    }
}
