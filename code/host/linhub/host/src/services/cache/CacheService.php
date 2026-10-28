<?php

declare(strict_types=1);

namespace linhub\host\services\cache;

use think\facade\Cache as TpCache;

/**
 * 缓存服务实现（宿主内部可替换，T1.7）。委托 think\Cache。
 */
class CacheService implements Cache
{
    public function get(string $key, mixed $default = null): mixed
    {
        return TpCache::get($key, $default);
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        TpCache::set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return TpCache::has($key);
    }

    public function delete(string $key): void
    {
        TpCache::delete($key);
    }
}