<?php

declare(strict_types=1);

namespace linhub\host\services\config;

/**
 * 配置服务契约（T1.7 / M8）
 *
 * 面向功能包的只读配置读取能力；配置写入/管理归宿主平台。功能包只能读取自身声明范围内的配置。
 */
interface Config
{
    /** 读取指定 key 的配置；不存在返回 $default。 */
    public function get(string $key, mixed $default = null): mixed;

    /** 判断配置是否存在。 */
    public function has(string $key): bool;

    /** 读取某功能包命名空间下的全部配置。 */
    public function package(string $packageShort): array;
}