<?php

declare(strict_types=1);

namespace linhub\host\services\dictionary;

/**
 * 数据字典服务契约（DD-1 ~ DD-9）。
 *
 * 以"功能包命名空间 + 业务 key"定位一条字典数据，value 支持标量与任意 JSON 结构。
 * 功能包侧（DD-8）：调用时绑定本包身份，只能写本包、读公开的他包数据；
 * 管理端侧（DD-9）：超管可跨包检索，默认只读。
 */
interface Dictionary
{
    /**
     * 保存（新增/更新）本包一条字典数据（DD-1/3/6）。
     *
     * @param string $package 调用功能包短名（托管方）
     * @param string $key     业务 key（包内唯一，如 theme.color）
     * @param mixed  $value   标量或数组/对象（数组以 JSON 持久化）
     * @param array  $meta    可选：is_public(bool)、remark(string)
     * @return array 落库后的完整条目
     */
    public function put(string $package, string $key, mixed $value, array $meta = []): array;

    /**
     * 读取一条字典（DD-4）。
     *
     * @param string      $package    调用功能包短名
     * @param string      $namespace  目标命名空间（归属功能包）
     * @param string      $key        业务 key
     * @param mixed       $default    不存在/无权时返回的默认值
     * @param bool        $asAdmin    是否超管视角（可读私有他包数据）
     */
    public function get(string $package, string $namespace, string $key, mixed $default = null, bool $asAdmin = false): mixed;

    /**
     * 删除本包一条字典（DD-7）。超管 $asAdmin 可删任意包。
     */
    public function remove(string $package, string $namespace, string $key, bool $asAdmin = false): bool;

    /**
     * 检索字典条目（DD-5/9）。
     *
     * 过滤条件：namespace、keyword（匹配 key）、is_public；
     * 功能包视角只能看本包全部 + 他包公开；超管视角不做可见性过滤。
     */
    public function search(string $package, array $filter = [], int $page = 1, int $size = 20, bool $asAdmin = false): array;

    /** 单条原始条目（含 value 原文与类型），供管理端/服务内部使用；无权返回 null。 */
    public function entry(string $package, string $namespace, string $key, bool $asAdmin = false): ?array;

    /** 按命名空间统计条目数（PS-1 看板用）。 */
    public function stats(): array;
}
