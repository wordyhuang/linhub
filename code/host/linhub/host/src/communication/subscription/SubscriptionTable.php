<?php

declare(strict_types=1);

namespace linhub\host\communication\subscription;

use linhub\host\communication\broker\Event;
use linhub\host\communication\broker\HandlerCaller;

/**
 * 订阅表与定向分发（T6.5 / N4 / N5）
 *
 * 从各功能包 manifest.subscribes 汇总出订阅路由表（topic => 多个 handler，每个 handler 带包归属）。
 * dispatch 仅向订阅了该主题的处理器（包）投递，未订阅的处理器不会被触发（非广播）。
 */
final class SubscriptionTable
{
    /** topic => list<array{package:string, handler:string}> */
    private array $routes = [];

    private function __construct()
    {
    }

    /**
     * 从已装功能包 manifest 列表汇总订阅路由表。
     *
     * @param array<int,array<string,mixed>> $manifests 每个元素含 short 与 subscribes
     */
    public static function build(array $manifests): self
    {
        $table = new self();
        foreach ($manifests as $manifest) {
            $short = (string) ($manifest['short'] ?? '');
            foreach ((array) ($manifest['subscribes'] ?? []) as $sub) {
                $topic = (string) ($sub['topic'] ?? '');
                $handler = (string) ($sub['handler'] ?? '');
                if ($topic !== '' && $handler !== '') {
                    $table->add($short, $topic, $handler);
                }
            }
        }
        return $table;
    }

    /** 登记一条订阅（幂等，同一包同一 handler 不重复）。 */
    public function add(string $package, string $topic, string $handler): void
    {
        $this->routes[$topic] ??= [];
        foreach ($this->routes[$topic] as $r) {
            if ($r['handler'] === $handler) {
                return;
            }
        }
        $this->routes[$topic][] = ['package' => $package, 'handler' => $handler];
    }

    /** 某主题的全部订阅者（含包归属）。 */
    public function handlersFor(string $topic): array
    {
        return $this->routes[$topic] ?? [];
    }

    /** 是否有某主题；package 指定时仅判断该包是否订阅。 */
    public function has(string $topic, ?string $package = null): bool
    {
        foreach ($this->routes[$topic] ?? [] as $r) {
            if ($package === null || $r['package'] === $package) {
                return true;
            }
        }
        return false;
    }

    /** 全部被订阅主题。 */
    public function topics(): array
    {
        return array_keys($this->routes);
    }

    /** 主题总数。 */
    public function count(): int
    {
        return count($this->routes);
    }

    /** 订阅条目总数（跨主题累计）。 */
    public function totalSubscriptions(): int
    {
        $n = 0;
        foreach ($this->routes as $list) {
            $n += count($list);
        }
        return $n;
    }

    /**
     * 定向分发：仅向订阅了该主题的处理器投递（非广播）。
     *
     * @return list<array{package:string, handler:string, ok:bool}>
     */
    public function dispatch(string $topic, array $payload = [], string $source = 'platform'): array
    {
        $event = new Event($topic, $payload, microtime(true), $source, uniqid('evt_', true));
        $out = [];
        foreach ($this->routes[$topic] ?? [] as $r) {
            $ok = false;
            try {
                HandlerCaller::call($r['handler'], $event);
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
            $out[] = ['package' => $r['package'], 'handler' => $r['handler'], 'ok' => $ok];
        }
        return $out;
    }
}