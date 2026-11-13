<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\inbox\InboxService;
use linhub\host\services\support\PlatformStore;
use linhub\host\support\HostBootstrap;

/**
 * 调度执行核心：一次 tick 完成「回收租约 → 到期定义生成实例 → 闸门内认领 → 执行 → 清理 → 记心跳」。
 *
 * 执行在闸门锁外同步完成（Lite 请求尾 / Pro 常驻 loop 均复用本类）；
 * 认领用条件 UPDATE 保证多进程不重复执行同一实例。
 */
final class SchedulerRunner
{
    private PlatformStore $store;

    private SchedulerSettings $settings;

    private TaskHandlerLoader $loader;

    /** 本 tick 各包已认领数，用于 package_batch_per_tick 限流。 */
    private array $claimedPerPackage = [];

    public function __construct(
        PlatformStore $store,
        SchedulerSettings $settings,
        TaskHandlerLoader $loader
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->loader = $loader;
    }

    public function tick(string $source): array
    {
        $started = microtime(true);
        $summary = [
            'source'    => $source,
            'generated' => 0,
            'reclaimed' => 0,
            'claimed'   => 0,
            'done'      => 0,
            'failed'    => 0,
            'cancelled' => 0,
            'skipped'   => 0,
            'message'   => '',
        ];
        if (!$this->settings->bool('scheduler.enabled')) {
            $summary['message'] = '服务未启用';
            return $summary;
        }

        $lock = $this->acquireLock();
        if ($lock === null) {
            $summary['message'] = '另一个 tick 正在执行，本次跳过';
            return $summary;
        }

        try {
            $now = date('c');
            $lease = $this->settings->int('scheduler.task.lease_sec');
            $grace = $this->settings->int('scheduler.task.stale_grace_sec');
            $summary['reclaimed'] = $this->store->reclaimStaleRuns(date('c', time() - $lease - $grace));

            $summary['generated'] = $this->materializeDueDefs($now);

            $budget = $this->settings->int('scheduler.exec.heartbeat_budget_sec');
            $batch = $this->settings->int('scheduler.exec.heartbeat_batch');
            $candidates = $this->store->claimableRuns($now, max($batch * 5, 20));
            $this->claimedPerPackage = [];

            foreach ($candidates as $row) {
                if ($summary['claimed'] >= $batch || microtime(true) - $started >= $budget) {
                    break;
                }
                if (!$this->admit($row)) {
                    $summary['skipped']++;
                    continue;
                }
                if (!$this->store->claimRun((int) $row['id'], $lease)) {
                    continue;
                }
                $namespace = (string) $row['namespace'];
                $this->claimedPerPackage[$namespace] = ($this->claimedPerPackage[$namespace] ?? 0) + 1;
                $summary['claimed']++;
                $state = $this->execute($row);
                $summary[$state] = ($summary[$state] ?? 0) + 1;
            }

            $this->cleanup();
            $summary['message'] = 'ok';
        } catch (\Throwable $e) {
            $summary['message'] = $e->getMessage();
            $this->log('error', 'tick 异常：' . $e->getMessage());
        } finally {
            $this->releaseLock($lock);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $this->beat($source, $summary, $elapsedMs);
        return $summary;
    }

    /** 到期周期定义生成实例；唯一键 (def_id, plan_time) 保证多进程幂等。 */
    private function materializeDueDefs(string $now): int
    {
        $generated = 0;
        foreach ($this->store->dueDefs($now, 50) as $def) {
            $planTime = (string) ($def['next_run'] ?? '');
            $inserted = $this->store->insertRunIgnore([
                'run_id'    => bin2hex(random_bytes(16)),
                'def_id'    => (int) $def['id'],
                'namespace' => (string) $def['namespace'],
                'task_key'  => (string) $def['def_key'],
                'handler'   => (string) $def['handler'],
                'state'     => 'queued',
                'payload'   => (string) $def['payload'],
                'plan_time' => $planTime,
                'created_at' => $now,
            ]);
            if ($inserted) {
                $generated++;
            }
            $timezone = (string) ($def['timezone'] ?? '');
            $from = $this->settings->string('scheduler.periodic.missed_policy') === 'catchup_once'
                ? $planTime
                : $now;
            try {
                $next = ScheduleExpression::next(
                    (string) $def['mode'],
                    (string) $def['expr'],
                    $from,
                    $timezone === '' ? 'UTC' : $timezone
                );
                $this->store->updateDef((int) $def['id'], [
                    'next_run'   => $next,
                    'last_run_at' => $now,
                    'last_status' => $inserted ? 'queued' : 'skipped',
                ]);
            } catch (\Throwable $e) {
                $this->store->updateDef((int) $def['id'], [
                    'enabled'    => 0,
                    'status'     => 'paused',
                    'last_status' => 'invalid_schedule',
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $this->log('warning', '周期表达式失效，已自动停用：' . (string) $def['def_key']);
            }
        }
        return $generated;
    }

    /** 三级闸门：全局并发、单包并发与批数、单处理器熔断。 */
    private function admit(array $row): bool
    {
        $namespace = (string) $row['namespace'];
        $handler = (string) $row['handler'];

        $globalLimit = $this->settings->int('scheduler.exec.global_max_concurrent');
        if ($this->store->countRunsInState('running') >= $globalLimit) {
            return false;
        }
        $packageLimit = $this->settings->int('scheduler.exec.package_max_concurrent');
        if ($this->store->countRunsInState('running', $namespace) >= $packageLimit) {
            return false;
        }
        $packageBatch = $this->settings->int('scheduler.exec.package_batch_per_tick');
        if (($this->claimedPerPackage[$namespace] ?? 0) >= $packageBatch) {
            return false;
        }
        return !$this->circuitOpen($namespace, $handler);
    }

    /** 单处理器熔断：窗口内样本足够且失败率超阈值时打开 cooldown。 */
    private function circuitOpen(string $namespace, string $handler): bool
    {
        if (!$this->settings->bool('scheduler.circuit.enabled')) {
            return false;
        }
        $state = $this->circuitState($namespace, $handler);
        $openUntil = (int) ($state['open_until'] ?? 0);
        return $openUntil > time();
    }

    private function circuitState(string $namespace, string $handler): array
    {
        $raw = $this->store->kvGet($this->circuitKey($namespace, $handler));
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function circuitKey(string $namespace, string $handler): string
    {
        return 'scheduler.circuit.' . $namespace . '.' . $handler;
    }

    /** 记录一次执行结果，维护滚动窗口与熔断打开。 */
    private function recordCircuit(string $namespace, string $handler, bool $success): void
    {
        if (!$this->settings->bool('scheduler.circuit.enabled')) {
            return;
        }
        $window = $this->settings->int('scheduler.circuit.window_sec');
        $minSamples = $this->settings->int('scheduler.circuit.min_samples');
        $threshold = $this->settings->float('scheduler.circuit.fail_rate_threshold');
        $cooldown = $this->settings->int('scheduler.circuit.cooldown_sec');

        $state = $this->circuitState($namespace, $handler);
        $windowStart = (int) ($state['window_start'] ?? 0);
        if ($windowStart === 0 || time() - $windowStart > $window) {
            $state = ['window_start' => time(), 'samples' => 0, 'failures' => 0, 'open_until' => 0];
        }
        $state['samples'] = (int) ($state['samples'] ?? 0) + 1;
        if (!$success) {
            $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        }
        if ((int) $state['samples'] >= $minSamples) {
            $rate = ((int) $state['failures']) / (int) $state['samples'];
            if ($rate >= $threshold) {
                $state['open_until'] = time() + $cooldown;
                $state = ['window_start' => time(), 'samples' => 0, 'failures' => 0, 'open_until' => $state['open_until']];
                $this->alert('handler 熔断打开：' . $namespace . ' / ' . $handler);
            }
        }
        $this->store->kvPut($this->circuitKey($namespace, $handler), (string) json_encode($state));
    }

    /** 执行单个已认领实例，返回终态。 */
    private function execute(array $row): string
    {
        $runId = (string) $row['run_id'];
        $namespace = (string) $row['namespace'];
        $handlerId = (string) $row['handler'];
        $timeout = $this->settings->int('scheduler.task.timeout_sec');

        try {
            $class = $this->loader->resolve($namespace, $handlerId);
        } catch (\Throwable $e) {
            $this->store->finishRun($runId, 'failed', [
                'error'       => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => date('c'),
            ]);
            $this->recordCircuit($namespace, $handlerId, false);
            $this->log('warning', '处理器解析失败：' . $namespace . ' / ' . $handlerId . '：' . $e->getMessage());
            return 'failed';
        }

        $payload = json_decode((string) $row['payload'], true);
        $context = new TaskContext(
            $runId,
            $namespace,
            (string) ($row['task_key'] ?? ''),
            is_array($payload) ? $payload : [],
            $timeout,
            function (int $percent, string $message) use ($runId): void {
                if ($percent >= 0) {
                    $this->store->updateRunProgress($runId, $percent, $message);
                }
                $this->store->heartbeatRun($runId, $this->settings->int('scheduler.task.lease_sec'));
            },
            function () use ($runId): bool {
                return $this->store->runCancelRequested($runId);
            }
        );

        try {
            $handler = new $class();
            if (!$handler instanceof TaskHandler) {
                throw new \RuntimeException('处理器未实现 TaskHandler');
            }
            $result = $handler->handle($context);
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '{}';
            }
            $limit = $this->settings->int('scheduler.quota.max_result_bytes');
            if (strlen($encoded) > $limit) {
                $encoded = (string) json_encode(
                    ['truncated' => true, 'bytes' => strlen($encoded)],
                    JSON_UNESCAPED_UNICODE
                );
            }
            $state = $this->store->runCancelRequested($runId) ? 'cancelled' : 'done';
            $this->store->finishRun($runId, $state, [
                'result'      => $encoded,
                'progress'    => 100,
                'finished_at' => date('c'),
            ]);
            $this->recordCircuit($namespace, $handlerId, true);
            $this->afterFinish($row, $state);
            return $state;
        } catch (\Throwable $e) {
            $this->store->finishRun($runId, 'failed', [
                'error'       => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => date('c'),
            ]);
            $this->recordCircuit($namespace, $handlerId, false);
            $this->afterFinish($row, 'failed');
            $this->log('warning', '任务执行失败：' . $runId . '：' . $e->getMessage());
            return 'failed';
        }
    }

    /** 结束后回写定义：成功清零失败计数，失败累计到阈值后自动停用；并按配置发完成通知。 */
    private function afterFinish(array $row, string $state): void
    {
        $defId = (int) ($row['def_id'] ?? 0);
        if ($defId > 0) {
            $def = $this->store->findDefById($defId);
            if ($def !== null) {
                if ($state === 'done') {
                    $this->store->updateDef($defId, ['fail_streak' => 0, 'last_status' => 'done', 'last_error' => '']);
                } else {
                    $streak = (int) ($def['fail_streak'] ?? 0) + 1;
                    $limit = $this->settings->int('scheduler.periodic.fail_pause_after');
                    $fields = [
                        'fail_streak' => $streak,
                        'last_status' => $state,
                        'last_error'  => mb_substr((string) ($row['error'] ?? '任务失败'), 0, 500),
                    ];
                    if ($streak >= $limit) {
                        $fields['enabled'] = 0;
                        $fields['status'] = 'paused';
                        $this->alert('周期任务连续失败 ' . $streak . ' 次已自动暂停：' . (string) $def['def_key']);
                    }
                    $this->store->updateDef($defId, $fields);
                }
            }
        }
        $this->notify($row, $state);
    }

    /** 站内信通知提交人（仅当配置开启且 created_by 非空）；失败不阻断。 */
    private function notify(array $row, string $state): void
    {
        if (!$this->settings->bool('scheduler.notify.default_on_done')) {
            return;
        }
        $user = (string) ($row['created_by'] ?? '');
        if ($user === '') {
            return;
        }
        $text = $state === 'done' ? '任务已完成' : ($state === 'cancelled' ? '任务已取消' : '任务失败');
        try {
            (new InboxService())->sendToUsers('scheduler', [
                'title'   => '异步任务' . $text,
                'content' => '任务 ' . (string) ($row['task_key'] ?? $row['handler']) . '（' . (string) $row['run_id'] . '）' . $text,
                'level'   => $state === 'failed' ? 'warning' : 'info',
            ], [$user], ['source' => 'scheduler', 'run_id' => (string) $row['run_id']]);
        } catch (\Throwable) {
            // 通知失败不影响任务终态
        }
    }

    /** 清理：幂等键与终态实例按保留策略回收，每 10 分钟最多跑一次。 */
    private function cleanup(): void
    {
        $last = $this->store->kvGet('scheduler.cleanup_at');
        if ($last !== null && $last !== '' && strtotime($last) !== false && time() - (int) strtotime($last) < 600) {
            return;
        }
        $this->store->kvPut('scheduler.cleanup_at', date('c'));
        try {
            $this->store->purgeIdem(date('c'));
            $days = $this->settings->int('scheduler.queue.terminal_retention_days');
            $this->store->purgeTerminalRuns(date('c', time() - $days * 86400), 500);
        } catch (\Throwable $e) {
            $this->log('warning', '清理失败：' . $e->getMessage());
        }
    }

    /** 写心跳行 + 最近一次 tick 摘要到 task_kv。 */
    private function beat(string $source, array $summary, int $elapsedMs): void
    {
        try {
            $row = [
                'worker'        => 'primary',
                'pid'           => function_exists('posix_getpid') ? posix_getpid() : 0,
                'os'            => PHP_OS_FAMILY,
                'sapi'          => PHP_SAPI,
                'mode'          => $this->settings->string('scheduler.mode'),
                'code_version'  => '1.1.0',
                'last_tick_ms'  => $elapsedMs,
                'last_message'  => mb_substr($summary['message'] . ' / claimed=' . $summary['claimed'], 0, 500),
            ];
            $this->store->touchHeartbeat($row);
            $this->store->kvPut('scheduler.last_tick', (string) json_encode([
                'at'      => date('c'),
                'source'  => $source,
                'elapsed_ms' => $elapsedMs,
                'summary' => $summary,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // 心跳写入失败不影响任务
        }
    }

    private function acquireLock()
    {
        $file = (new HostBootstrap())->schedulerLockFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function alert(string $message): void
    {
        if (!$this->settings->bool('scheduler.alert.enabled')) {
            return;
        }
        $this->log('warning', $message);
    }

    private function log(string $level, string $message): void
    {
        try {
            $dir = (new HostBootstrap())->schedulerLogDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(
                $dir . DIRECTORY_SEPARATOR . 'scheduler-' . date('Ymd') . '.log',
                '[' . date('c') . '] ' . strtoupper($level) . ' ' . $message . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // 日志失败不阻断
        }
    }
}
