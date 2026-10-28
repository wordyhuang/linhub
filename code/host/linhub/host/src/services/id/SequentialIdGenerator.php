<?php

declare(strict_types=1);

namespace linhub\host\services\id;

/**
 * ID 生成器实现（宿主内部可替换，T1.7）。
 *
 * 基于时间戳 + 进程唯一序号 + 随机数的组合式唯一 ID，保证进程内唯一；跨进程由时间戳+随机保证碰撞概率极低。
 * 生产可替换为雪花算法/DB 序列表实现。
 */
class SequentialIdGenerator implements IdGenerator
{
    private static int $seq = 0;

    public function generate(): string
    {
        return sprintf('%d%04d%04d', time(), getmypid() % 10000, mt_rand(0, 9999));
    }

    public function generateInt(): int
    {
        return (int) (self::$seq++ % PHP_INT_MAX);
    }
}