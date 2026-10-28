<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

use think\facade\Env;

/**
 * 附件存储驱动工厂（AT-2）。
 *
 * 用法：StorageFactory::make() 按 ATTACH_DRIVER 返回默认驱动；
 * 也可 StorageFactory::make('qiniu') 显式指定。未知驱动抛异常，绝不静默降级。
 */
final class StorageFactory
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_QINIU = 'qiniu';

    /**
     * 创建存储驱动实例。
     *
     * @param string $driver 驱动名，空串读 ATTACH_DRIVER（默认 local）
     */
    public static function make(string $driver = ''): StorageInterface
    {
        $driver = strtolower(trim($driver !== '' ? $driver : (string) Env::get('ATTACH_DRIVER', self::DRIVER_LOCAL)));

        return match ($driver) {
            self::DRIVER_LOCAL => self::makeLocal(),
            self::DRIVER_QINIU => new QiniuDriver(),
            default => throw new \InvalidArgumentException("不支持的附件存储驱动：{$driver}"),
        };
    }

    /** 支持的驱动清单（配置页用）。 */
    public static function drivers(): array
    {
        return [
            self::DRIVER_LOCAL => '本地磁盘',
            self::DRIVER_QINIU => '七牛云存储',
        ];
    }

    private static function makeLocal(): LocalDriver
    {
        $root = (string) Env::get('ATTACH_BASE_DIR', '');
        return new LocalDriver($root);
    }
}
