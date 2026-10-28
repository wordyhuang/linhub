<?php

declare(strict_types=1);

namespace linhub\host\services\messaging;

use linhub\host\communication\broker\Broker;
use linhub\host\communication\broker\HandlerCaller;
use linhub\host\communication\broker\MemoryBroker;

/**
 * 消息/事件服务实现（MessagingService）。
 *
 * 将宿主事件总线 Broker 对功能包/宿主统一暴露：
 *  - 功能包注入 messaging 后，可 publish 自身事件、subscribe 本方或跨包（外部）主题；
 *  - 宿主在功能包安装/升级/卸载/打包等生命周期节点经 broadcastLifecycle() 向所有订阅方广播。
 *
 * 进程内共享一个 MemoryBroker（T6.3 可离线验证）：无论经宿主控制器还是功能包注入的实例
 * 持有的是同一总线，保证「谁发、谁能订阅到」在进程内一致。生产可平滑替换为 RedisStreamBroker
 * （见 Broker 契约）实现跨进程投递。
 *
 * 无参友好构造（ServiceInjector 以 $app->make() 装配）；broker 可注入以便测试，缺省共享单例。
 */
final class MessagingService implements Messaging
{
    /** 宿主生命周期广播主题前缀（宿主保留：lg.platform.package.*）。 */
    public const LIFECYCLE_PREFIX = 'lg.platform.package.';

    /** 事件：安装完成  upgrade=升级  remove=卸载  packed=打包。 */
    public const EVENT_INSTALLED = 'installed';
    public const EVENT_UPGRADED  = 'upgraded';
    public const EVENT_REMOVED   = 'removed';
    public const EVENT_PACKED    = 'packed';

    private static ?Broker $broker = null;

    /**
     * @param Broker|null $broker 注入为 null 时共享进程内单例；注入时不覆盖他人已建的单例。
     */
    public function __construct(?Broker $broker = null)
    {
        if ($broker !== null && self::$broker === null) {
            self::$broker = $broker;
        }
    }

    /** 进程内共享 Broker 单例。 */
    public static function broker(): Broker
    {
        return self::$broker ??= new MemoryBroker();
    }

    public function publish(string $topic, array $payload = []): void
    {
        self::broker()->publish($topic, $payload, 'platform');
    }

    public function subscribe(string $topic, string $handler): void
    {
        self::broker()->subscribe($topic, $handler);
    }

    /**
     * 宿主生命周期广播：发布 lg.platform.package.<event>，向已订阅该主题的所有处理器投递。
     *
     * @param string $event 取自 EVENT_ 常量
     * @param array<string,mixed> $extra 额外载荷（如版本号）
     * @return array<string,mixed> 投递结果摘要（绝不因消费者失败抛异常）
     */
    public static function broadcastLifecycle(string $event, string $short, array $extra = []): array
    {
        $payload = array_merge(['short' => $short], $extra);
        return self::broker()->publish(self::LIFECYCLE_PREFIX . $event, $payload, 'platform');
    }

    /**
     * 从功能包 manifest 列表汇总订阅并注册进共享总线（协议 5.3：订阅声明）。
     *
     * @param array<int,array<string,mixed>> $manifests 每个元素含 short 与 subscribes
     * @return int 注册的订阅条目数
     */
    public static function subscribeFromManifests(array $manifests): int
    {
        $n = 0;
        foreach ($manifests as $manifest) {
            $short = (string) ($manifest['short'] ?? '');
            foreach ((array) ($manifest['subscribes'] ?? []) as $sub) {
                $topic   = (string) ($sub['topic'] ?? '');
                $handler = (string) ($sub['handler'] ?? '');
                if ($topic === '' || $handler === '' || !HandlerCaller::isHandler($handler)) {
                    continue; // 非法的订阅声明被 ManifestValidator M008 拦下；此处仅注册合法项
                }
                self::broker()->subscribe($topic, $handler, $short);
                $n++;
            }
        }
        return $n;
    }
}