<?php

declare(strict_types=1);

namespace linhub\host\communication\logger;

/**
 * 统一日志流水线单条记录（T6.2 / M4）
 *
 * 一条已归集日志的值对象：统一携带 app_id 与 package_id，来源可区分（platform|package）。
 */
final class LogRecord
{
    /**
     * @param string $appId     应用 id（平台 app / 功能包归属 app）
     * @param string $packageId 功能包 id；空串表示平台自产日志
     * @param string $level     级别 info/warning/error/debug
     * @param string $message   消息
     * @param array<string,mixed> $context 结构化上下文
     * @param string $source    来源标记 platform|package（自动推导）
     * @param string $createdAt 写入时间 Y-m-d H:i:s
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $packageId,
        public readonly string $level,
        public readonly string $message,
        public readonly array $context,
        public readonly string $source,
        public readonly string $createdAt,
    ) {
    }
}