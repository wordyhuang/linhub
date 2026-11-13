<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\scheduler\exception\SchedulerException;
use linhub\host\services\scheduler\exception\TaskInvalidHandler;
use linhub\host\services\scheduler\exception\TaskPayloadTooLarge;
use linhub\host\services\scheduler\exception\TaskQueueFull;
use linhub\host\services\scheduler\exception\TaskQuotaExceeded;
use linhub\host\services\support\PlatformStore;

/**
 * 异步任务托管服务实现（宿主侧唯一实现，不直接暴露给功能包）。
 *
 * 所有方法都以「包短名 namespace」为首参，由 BoundScheduler 焊死身份后转发；
 * 管理端不走本类，直接读 PlatformStore，避免管理操作被单包身份限制。
 */
final class SchedulerService
{
    private PlatformStore $store;

    private SchedulerSettings $settings;

    private TaskHandlerLoader $loader;

    public function __construct(
        ?PlatformStore $store = null,
        ?SchedulerSettings $settings = null,
        ?TaskHandlerLoader $loader = null
    ) {
        $this->store = $store ?? new PlatformStore();
        $this->settings = $settings ?? new SchedulerSettings();
        $this->loader = $loader ?? new TaskHandlerLoader();
        SchedulerTriggerSource::attach($this);
    }

    public function store(): PlatformStore
    {
        return $this->store;
    }

    public function settings(): SchedulerSettings
    {
        return $this->settings;
    }

    public function handlerLoader(): TaskHandlerLoader
    {
        return $this->loader;
    }

    /**
     * 提交一次性任务。
     *
     * @throws SchedulerException
     */
    public function submit(string $namespace, string $handler, array $payload = [], array $options = []): string
    {
        $this->assertEnabled();
        $handler = $this->assertHandler($handler);
        $encoded = $this->encodePayload($payload);

        $idemKey = $this->idempotencyEnabled() ? trim((string) ($options['idem_key'] ?? '')) : '';
        if ($idemKey !== '') {
            $hit = $this->store->findIdem($namespace, $idemKey, date('c'));
            if ($hit !== null && $hit !== '') {
                return $hit;
            }
        }

        $this->assertQueueCapacity($namespace);

        $runId = bin2hex(random_bytes(16));
        $planTime = $this->resolvePlanTime($options);
        try {
            $this->store->insertRun([
                'run_id'    => $runId,
                'namespace' => $namespace,
                'task_key'  => (string) ($options['key'] ?? ''),
                'handler'   => $handler,
                'state'     => 'queued',
                'priority'  => (int) ($options['priority'] ?? 0),
                'payload'   => $encoded,
                'plan_time' => $planTime,
                'idem_key'  => $idemKey,
                'created_by' => (string) ($options['created_by'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            if ($idemKey !== '') {
                $hit = $this->store->findIdem($namespace, $idemKey, date('c'));
                if ($hit !== null && $hit !== '') {
                    return $hit;
                }
            }
            throw $e;
        }

        if ($idemKey !== '') {
            $ttl = max(1, $this->settings->int('scheduler.idempotency.ttl_sec'));
            $this->store->putIdem($namespace, $idemKey, $runId, date('c', time() + $ttl));
        }

        return $runId;
    }

    /**
     * 声明（新增或覆盖）周期任务。
     *
     * @throws SchedulerException
     */
    public function define(string $namespace, string $key, string $handler, string $expr, array $options = []): array
    {
        $this->assertEnabled();
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 128) {
            throw new SchedulerException('定义键必须为 1~128 字符');
        }
        $handler = $this->assertHandler($handler);
        $mode = (string) ($options['mode'] ?? 'interval');
        $expr = trim($expr);
        ScheduleExpression::validate($mode, $expr);

        $existing = $this->store->findDef($namespace, $key);
        if ($existing === null) {
            $limit = $this->settings->int('scheduler.quota.max_periodic_defs_per_package');
            if ($this->store->countActiveDefs($namespace) >= $limit) {
                throw new TaskQuotaExceeded('周期定义数已达上限：' . $limit);
            }
        }

        $payload = $options['payload'] ?? null;
        if ($payload === null && $existing !== null) {
            $decoded = json_decode((string) $existing['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $encoded = $this->encodePayload(is_array($payload) ? $payload : []);
        $enabled = (bool) ($options['enabled'] ?? true);
        $timezone = (string) ($options['timezone'] ?? ($existing['timezone'] ?? ''));

        $nextRun = '';
        if ($enabled) {
            $nextRun = ScheduleExpression::next($mode, $expr, date('c'), $timezone === '' ? 'UTC' : $timezone);
        }

        $this->store->upsertDef([
            'namespace'  => $namespace,
            'def_key'    => $key,
            'handler'    => $handler,
            'mode'       => $mode,
            'expr'       => $expr,
            'timezone'   => $timezone,
            'payload'    => $encoded,
            'timeout_sec' => $this->resolveTimeout($options),
            'next_run'   => $nextRun,
            'created_by' => (string) ($options['created_by'] ?? ''),
        ]);
        if (!$enabled) {
            $this->store->setDefEnabled($namespace, $key, false);
        }
        $def = $this->store->findDef($namespace, $key);
        return $this->defSnapshot($def);
    }

    public function pause(string $namespace, string $key): bool
    {
        return $this->store->setDefEnabled($namespace, $key, false);
    }

    public function resume(string $namespace, string $key): bool
    {
        $def = $this->store->findDef($namespace, $key);
        if ($def === null) {
            return false;
        }
        $timezone = (string) ($def['timezone'] ?? '');
        $next = ScheduleExpression::next(
            (string) $def['mode'],
            (string) $def['expr'],
            date('c'),
            $timezone === '' ? 'UTC' : $timezone
        );
        return $this->store->setDefEnabled($namespace, $key, true, $next);
    }

    /** 立即触发一次周期任务（使用定义上的 handler / payload，不影响原定 next_run）。 */
    public function triggerNow(string $namespace, string $key, array $payload = []): string
    {
        $def = $this->store->findDef($namespace, $key);
        if ($def === null) {
            throw new SchedulerException('周期定义不存在：' . $key);
        }
        if ($payload === []) {
            $decoded = json_decode((string) $def['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        return $this->submit($namespace, (string) $def['handler'], $payload, [
            'key'        => $key,
            'priority'   => 10,
            'timeout_sec' => (int) $def['timeout_sec'],
        ]);
    }

    public function nextRun(string $namespace, string $key): ?string
    {
        $def = $this->store->findDef($namespace, $key);
        if ($def === null) {
            return null;
        }
        $next = (string) ($def['next_run'] ?? '');
        return $next === '' ? null : $next;
    }

    /** 查询实例状态；跨包或不存在返回 null。 */
    public function status(string $namespace, string $runId): ?array
    {
        $row = $this->store->findRun($runId);
        if ($row === null || (string) $row['namespace'] !== $namespace) {
            return null;
        }
        $result = null;
        if ((string) ($row['result'] ?? '') !== '') {
            $result = json_decode((string) $row['result'], true);
        }
        return [
            'run_id'           => (string) $row['run_id'],
            'task_key'         => (string) ($row['task_key'] ?? ''),
            'handler'          => (string) ($row['handler'] ?? ''),
            'state'            => (string) ($row['state'] ?? ''),
            'progress'         => (int) ($row['progress'] ?? 0),
            'message'          => (string) ($row['message'] ?? ''),
            'attempt'          => (int) ($row['attempt'] ?? 0),
            'error'            => (string) ($row['error'] ?? ''),
            'cancel_requested' => (int) ($row['cancel_requested'] ?? 0),
            'result'           => $result,
            'created_at'       => (string) ($row['created_at'] ?? ''),
            'started_at'       => (string) ($row['started_at'] ?? ''),
            'finished_at'      => (string) ($row['finished_at'] ?? ''),
        ];
    }

    /** 取消：queued 直接取消；running 打标记；终态返回 false。 */
    public function cancel(string $namespace, string $runId): bool
    {
        $row = $this->store->findRun($runId);
        if ($row === null || (string) $row['namespace'] !== $namespace) {
            return false;
        }
        return match ((string) $row['state']) {
            'queued'  => $this->store->cancelQueuedRun($runId, $namespace),
            'running' => $this->store->requestRunCancel($runId, $namespace),
            default   => false,
        };
    }

    public function list(string $namespace, array $filter = [], int $page = 1, int $size = 20): array
    {
        $filter['namespace'] = $namespace;
        return $this->store->listDefs($filter, max(1, $page), max(1, min(200, $size)));
    }

    public function runs(string $namespace, array $filter = [], int $page = 1, int $size = 20): array
    {
        $filter['namespace'] = $namespace;
        return $this->store->listRuns($filter, max(1, $page), max(1, min(200, $size)));
    }

    /** 包卸载：周期定义转 orphaned、实例打孤儿标记。 */
    public function orphanPackage(string $namespace): void
    {
        $this->store->orphanPackageRows($namespace, date('c'));
    }

    /** 包安装/升级：解除孤儿标记（不自动恢复执行，需显式 resume）。 */
    public function adoptPackage(string $namespace): void
    {
        $this->store->adoptPackageRows($namespace);
    }

    /** 服务是否启用（GUI 与 doCommand 用）。 */
    public function enabled(): bool
    {
        return $this->settings->bool('scheduler.enabled');
    }

    /** 进程内执行一次 tick（由心跳/请求尾触发）。 */
    public function tick(string $source): array
    {
        return (new SchedulerRunner($this->store, $this->settings, $this->loader))->tick($source);
    }

    /** 包视角或平台视角的用量指标。 */
    public function metrics(?string $namespace = null): array
    {
        return [
            'states'   => $this->store->countRunsByState($namespace),
            'defs'     => $namespace === null ? null : $this->store->countActiveDefs($namespace),
            'mode'     => $this->settings->string('scheduler.mode'),
            'enabled'  => $this->enabled(),
            'queued_limit_global'    => $this->settings->int('scheduler.queue.max_queued_global'),
            'queued_limit_package'   => $this->settings->int('scheduler.queue.max_queued_per_package'),
            'concurrent_global'      => $this->settings->int('scheduler.exec.global_max_concurrent'),
            'concurrent_package'     => $this->settings->int('scheduler.exec.package_max_concurrent'),
        ];
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new SchedulerException('异步任务托管服务未启用（scheduler.enabled=0）');
        }
    }

    private function assertHandler(string $handler): string
    {
        $handler = trim($handler);
        if ($handler === '' || mb_strlen($handler) > 191) {
            throw new TaskInvalidHandler('处理器标识必须为 1~191 字符');
        }
        return $handler;
    }

    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new SchedulerException('payload 序列化失败');
        }
        $limit = $this->settings->int('scheduler.quota.max_payload_bytes');
        if (strlen($json) > $limit) {
            throw new TaskPayloadTooLarge('payload 超出上限：' . strlen($json) . ' > ' . $limit);
        }
        return $json;
    }

    private function assertQueueCapacity(string $namespace): void
    {
        $perPackage = $this->settings->int('scheduler.queue.max_queued_per_package');
        if ($this->store->countRunsInState('queued', $namespace) >= $perPackage) {
            $this->rejectOrAllow('单包排队数已达上限：' . $perPackage);
        }
        $global = $this->settings->int('scheduler.queue.max_queued_global');
        if ($this->store->countRunsInState('queued') >= $global) {
            $this->rejectOrAllow('全平台排队数已达上限：' . $global);
        }
    }

    private function rejectOrAllow(string $message): void
    {
        if ($this->settings->string('scheduler.queue.overflow_policy') === 'reject') {
            throw new TaskQueueFull($message);
        }
    }

    private function resolvePlanTime(array $options): string
    {
        $delay = (int) ($options['delay_sec'] ?? 0);
        if ($delay > 0) {
            return date('c', time() + $delay);
        }
        $planTime = trim((string) ($options['plan_time'] ?? ''));
        if ($planTime !== '') {
            try {
                return (new \DateTimeImmutable($planTime))->format('c');
            } catch (\Throwable) {
                throw new SchedulerException('plan_time 不是合法时间：' . $planTime);
            }
        }
        return date('c');
    }

    private function resolveTimeout(array $options): int
    {
        $cap = $this->settings->int('scheduler.task.timeout_sec');
        $requested = (int) ($options['timeout_sec'] ?? 0);
        if ($requested <= 0) {
            return $cap;
        }
        return min($requested, $cap);
    }

    private function idempotencyEnabled(): bool
    {
        return $this->settings->bool('scheduler.idempotency.enabled');
    }

    private function defSnapshot(?array $def): array
    {
        if ($def === null) {
            return [];
        }
        return [
            'def_key'   => (string) $def['def_key'],
            'handler'   => (string) $def['handler'],
            'mode'      => (string) $def['mode'],
            'expr'      => (string) $def['expr'],
            'status'    => (string) $def['status'],
            'enabled'   => (int) $def['enabled'] === 1,
            'next_run'  => (string) ($def['next_run'] ?? ''),
        ];
    }
}
