<?php

declare(strict_types=1);

namespace linhub\host\services\cache;

/**
 * 缓存服务契约（T1.7 / M8）
 *
 * 面向功能包的缓存读写能力；底层驱动（Redis/Memory）由宿主实现。ttl 秒，<=0 表示不过期。
 */
interface Cache
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, int $ttl = 0): void;

    public function has(string $key): bool;

    public function delete(string $key): void;
}