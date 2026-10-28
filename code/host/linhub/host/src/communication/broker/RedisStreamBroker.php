<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * Redis Stream 事件总线骨架（T6.3 / N6 / Redis Stream）
 *
 * 基于 Redis Stream 的发布/订阅骨架。Redis 客户端以可注入接口 RedisStreamClient
 * 占位；验证环境没有 Redis 时传入 null，则该 Broker **仍可构造**、可订阅，
 * publish 降级为"不可用但不抛错"，不破坏其余测试。
 *
 * submit：发布经 XADD 写入 stream；订阅在本地路由表登记并发起消费组（骨架）。
 * 消费端的 XREADGROUP + 失败投递（Pending/ACK）留待生产实现，本骨架只保证：
 *   1) 无 Redis 也可构造与订阅；
 *   2) publish 绝不因后端或消费者失败而抛错。
 */
final class RedisStreamBroker implements Broker
{
    private ?RedisStreamClient $client;

    private string $streamPrefix;

    /** topic => list<array{handler:string, package:string}> */
    private array $subscriptions = [];

    public function __construct(?RedisStreamClient $client = null, string $streamPrefix = 'lg:stream:')
    {
        $this->client = $client;
        $this->streamPrefix = $streamPrefix;
    }

    public function isClientAvailable(): bool
    {
        return $this->client !== null;
    }

    public function subscribe(string $topic, string $handler, string $package = ''): void
    {
        $this->subscriptions[$topic] ??= [];
        foreach ($this->subscriptions[$topic] as $sub) {
            if ($sub['handler'] === $handler) {
                return;
            }
        }
        $this->subscriptions[$topic][] = ['handler' => $handler, 'package' => $package];
        if ($this->client !== null) {
            try {
                $this->client->createGroup($this->streamPrefix . $topic, 'lg-consumers');
            } catch (\Throwable) {
                // 消费组创建失败不阻断发布链路
            }
        }
    }

    public function publish(string $topic, array $payload = [], string $source = 'platform'): array
    {
        if ($this->client === null) {
            // 无 Redis：降级为不发布，但不抛错，不影响调用方
            return ['topic' => $topic, 'source' => $source, 'stream' => false, 'clientAvailable' => false];
        }

        $adds = 0;
        try {
            $this->client->xadd(
                $this->streamPrefix . $topic,
                ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'source' => $source]
            );
            $adds++;
        } catch (\Throwable) {
            // 后端失败：不向发布方抛错
        }
        return ['topic' => $topic, 'source' => $source, 'stream' => true, 'xadd' => $adds, 'subscriptions' => count($this->subscriptions[$topic] ?? [])];
    }

    /** 某主题订阅数（路由表）。 */
    public function subscriptionCount(string $topic): int
    {
        return count($this->subscriptions[$topic] ?? []);
    }
}