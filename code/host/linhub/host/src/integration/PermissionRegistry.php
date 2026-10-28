<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 权限项接入（T4.1 / A4 / J7 / J7-b / J8）
 *
 * 依据 permissions.php 解析并登记权限点，code 采用「产品:资源:操作」编码（J8）。
 * roles 指定时仅对列出角色可见可授权；省略或为空数组表示不限制角色（J7-b）。
 * 提供权限注册与查询能力；非法编码跳过并记警告，不影响包其他装配。
 */
final class PermissionRegistry
{
    /** 分区键 */
    private const SECTION = 'permissions';

    private IntegrationRegistry $store;

    public function __construct(IntegrationRegistry $store)
    {
        $this->store = $store;
    }

    /**
     * 校验权限编码（M009，段间非空）：
     *  - 三段「产品:资源:操作」（如 host:role:view）
     *  - 四段「产品:资源域:资源:操作」（如 host:llm:model:view）
     */
    public static function isValidCode(string $code): bool
    {
        if ($code === '') {
            return false;
        }
        $colonCount = substr_count($code, ':');
        if ($colonCount < 2 || $colonCount > 3) {
            return false;
        }
        // 每一段非空
        foreach (explode(':', $code) as $seg) {
            if (trim($seg) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 登记一个功能包的权限点声明。
     *
     * @param array<int, array<string, mixed>> $defs permissions.php 返回的权限项数组
     * @return list<string> 警告（非法编码被跳过）
     */
    public function register(string $short, array $defs): array
    {
        $warnings = [];
        $list = [];
        foreach ($defs as $d) {
            $code = (string) ($d['code'] ?? '');
            if (!self::isValidCode($code)) {
                $warnings[] = "权限编码非法，已跳过：{$code}（应为 产品:资源:操作）";
                continue;
            }
            $list[] = [
                'code'        => $code,
                'title'       => (string) ($d['title'] ?? ''),
                'description' => (string) ($d['description'] ?? ''),
                'group'       => (string) ($d['group'] ?? ''),
                'roles'       => array_values((array) ($d['roles'] ?? [])), // 空则不限角色（J7-b）
                'short'       => $short,
            ];
        }
        $this->store->save($short, [self::SECTION => $list]);
        return $warnings;
    }

    /**
     * 全部已登记权限点（合并各包）。
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
                $out[] = $item;
            }
        }
        return $out;
    }

    /** 按编码取一个权限点。 */
    public function get(string $code): ?array
    {
        foreach ($this->all() as $p) {
            if (($p['code'] ?? '') === $code) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 对给定角色可见的权限点（T4.1/A4/J7）。
     * 权限点 roles 为空 => 对所有角色可见；roles 非空 => 仅当与给定角色有交集才可见。
     *
     * @param list<string> $roles 用户当前角色
     * @return list<array<string, mixed>>
     */
    public function visibleTo(array $roles): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $p): bool => ((array) ($p['roles'] ?? [])) === []
                || count(array_intersect((array) ($p['roles'] ?? []), $roles)) > 0,
        ));
    }
}