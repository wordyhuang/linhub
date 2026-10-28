<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * Redis Stream 客户端接口（T6.3 / RedisStreamBroker 依赖占位）
 *
 * 验证环境未必有 Redis，故以可注入接口占位。宿主生产环境可注入
 * 真实实现（Predis / phpredis 封装）。RedisStreamBroker 只面向本接口编程。
 */
interface RedisStreamClient
{
    /** 向 stream 追加一条消息，返回消息 id。 */
    public function xadd(string $stream, array $fields): string;

    /** 创建消费组（幂等，已存在时忽略）。 */
    public function createGroup(string $stream, string $group): void;
}