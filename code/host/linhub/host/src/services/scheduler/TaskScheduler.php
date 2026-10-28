<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * 任务调度契约（T1.7 / M8）
 *
 * 面向功能包的定时任务注册能力；执行由宿主调度器（Cron/队列）承担。
 */
interface TaskScheduler
{
    /** 注册一个定时任务。cron 为 CRON 表达式。 */
    public function schedule(string $id, string $cron, string $handler): void;

    /** 取消一个定时任务。 */
    public function cancel(string $id): void;
}