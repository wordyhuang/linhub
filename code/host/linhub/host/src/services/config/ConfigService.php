<?php

declare(strict_types=1);

namespace linhub\host\services\config;

use think\facade\Config as TpConfig;

/**
 * 配置服务实现（宿主内部可替换，T1.7）。
 *
 * 委托 think\Config；包内配置以 `pack.<short>` 命名空间隔离读取。
 */
class ConfigService implements Config
{
    public function get(string $key, mixed $default = null): mixed
    {
        return TpConfig::get($key, $default);
    }

    public function has(string $key): bool
    {
        return TpConfig::has($key);
    }

    public function package(string $packageShort): array
    {
        return (array) TpConfig::get('pack.' . $packageShort, []);
    }
}