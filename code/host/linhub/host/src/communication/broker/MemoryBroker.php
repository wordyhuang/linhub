<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

/**
 * 进程内事件总线（T6.3 / 可离线验证）
 *
 * 基于内存订阅表（topic => handler 列表）实现发布/订阅，不依赖外部服务，
 * 供开发、单测与验证环境使用。失败处理器按 maxRetry 重试，最终仍失败则**吞掉**，
 * 保证发布方 publish 不因消费者失败而抛错（重试不影响发布方）。
 */
final class MemoryBroker implements Broker
{
    /** topic => list<array{handler:string, package:string}> */
    private array $handlers = [];

    /** topic => ['delivered' => int, 'attempts' => int] 投递统计 */
    private array $stats = [];

    private int $maxRetry;

    public function __construct(int $maxRetry = 3)
    {
        $this->maxRetry = $maxRetry;
    }

    public function subscribe(string $topic, string $handler, string $package = ''): void
    {
        $this->handlers[$topic] ??= [];
        foreach ($this->handlers[$topic] as $sub) {
            if ($sub['handler'] === $handler) {
                return; // 幂等，防重复订阅
            }
        }
        $this->handlers[$topic][] = ['handler' => $handler, 'package' => $package];
    }

    public function publish(string $topic, array $payload = [], string $source = 'platform'): array
    {
        $event = new Event($topic, $payload, microtime(true), $source, uniqid('evt_', true));
        $this->stats[$topic] ??= ['delivered' => 0, 'attempts' => 0];

        $result = [
            'topic'     => $topic,
            'source'    => $source,
            'handlers'  => count($this->handlers[$topic] ?? []),
            'delivered' => 0,
            'failed'    => 0,
        ];

        foreach (($this->handlers[$topic] ?? []) as $sub) {
            $ok = false;
            for ($i = 0; $i <= $this->maxRetry; $i++) {
                $this->stats[$topic]['attempts']++;
                try {
                    HandlerCaller::call($sub['handler'], $event);
                    $ok = true;
                    break;
                } catch (\Throwable) {
                    // 重试或最终放弃：吞掉，不影响发布方
                }
            }
            if ($ok) {
                $this->stats[$topic]['delivered']++;
                $result['delivered']++;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }

    /** 某主题投递统计。 */
    public function stats(string $topic): array
    {
        return $this->stats[$topic] ?? ['delivered' => 0, 'attempts' => 0];
    }

    /** 某主题订阅数。 */
    public function subscriberCount(string $topic): int
    {
        return count($this->handlers[$topic] ?? []);
    }
}