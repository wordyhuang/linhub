<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * Lite 档的「请求尾」触发源。
 *
 * 每个 PHP 请求结束时注册一次 shutdown 回调，先 fastcgi_finish_request() 断开浏览器，
 * 再在后台薄跑一次 tick（是否有活由 tick 自己判断）。限制最小间隔，避免每个请求都扫库。
 */
final class SchedulerTriggerSource
{
    private static bool $attached = false;

    private static float $lastTailAt = 0.0;

    /** 最小触发间隔（秒）：同一进程内两次请求尾 tick 之间至少间隔。 */
    private const MIN_INTERVAL_SEC = 10;

    public static function attach(SchedulerService $service): void
    {
        if (self::$attached || PHP_SAPI === 'cli') {
            return;
        }
        if (!$service->enabled()) {
            return;
        }
        if ($service->settings()->string('scheduler.mode') !== 'lite') {
            return;
        }
        if (!in_array($service->settings()->string('scheduler.lite.trigger'), ['auto', 'request_tail'], true)) {
            return;
        }
        self::$attached = true;
        register_shutdown_function(static function () use ($service): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $now = microtime(true);
            if ($now - self::$lastTailAt < self::MIN_INTERVAL_SEC) {
                return;
            }
            self::$lastTailAt = $now;
            try {
                $service->tick('request_tail');
            } catch (\Throwable $e) {
                @error_log('[scheduler] request_tail tick 失败：' . $e->getMessage());
            }
        });
    }

    /**
     * 惰性绑定：宿主启动时只登记回调，真正构造服务与检查配置推迟到请求尾，
     * 避免每个请求在 boot 阶段就建立数据库连接。闭包应返回 SchedulerService 或 null。
     *
     * @param callable():?SchedulerService $factory
     */
    public static function attachLazy(callable $factory): void
    {
        if (self::$attached || PHP_SAPI === 'cli') {
            return;
        }
        self::$attached = true;
        register_shutdown_function(static function () use ($factory): void {
            $now = microtime(true);
            if ($now - self::$lastTailAt < self::MIN_INTERVAL_SEC) {
                return;
            }
            self::$lastTailAt = $now;
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $settings = new SchedulerSettings();
                if (!$settings->bool('scheduler.enabled')) {
                    return;
                }
                if ($settings->string('scheduler.mode') !== 'lite') {
                    return;
                }
                if (!in_array($settings->string('scheduler.lite.trigger'), ['auto', 'request_tail'], true)) {
                    return;
                }
                $service = $factory();
                if ($service instanceof SchedulerService) {
                    $service->tick('request_tail');
                }
            } catch (\Throwable $e) {
                @error_log('[scheduler] request_tail tick 失败：' . $e->getMessage());
            }
        });
    }
}
