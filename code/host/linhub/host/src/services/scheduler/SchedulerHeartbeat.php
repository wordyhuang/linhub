<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\support\PlatformStore;
use linhub\host\support\HostBootstrap;

/**
 * 心跳进程封装（Pro 常驻 / `--once` 单次）。
 *
 * 常驻模式持有 scheduler_heartbeat.lock（全生命周期），每轮复用 SchedulerRunner::tick()；
 * 单次模式下与请求尾触发共用同一个 tick 互斥锁，二者互不重复执行。
 */
final class SchedulerHeartbeat
{
    /** 代码版本，与 services.php 中 scheduler 服务版本保持一致。 */
    public const CODE_VERSION = '1.1.0';

    private SchedulerService $service;

    private PlatformStore $store;

    private SchedulerSettings $settings;

    public function __construct(?SchedulerService $service = null)
    {
        $this->service = $service ?? new SchedulerService();
        $this->store = $this->service->store();
        $this->settings = $this->service->settings();
    }

    /** 执行一次 tick 后返回摘要（`--once`、cron、手动触发共用）。 */
    public function once(string $source = 'cli'): array
    {
        return $this->service->tick($source);
    }

    /**
     * 常驻循环：每轮 tick 后补足到 interval 秒，异常不退出循环。
     *
     * @param callable|null $onTick 每轮回调，参数为本轮摘要
     * @throws \RuntimeException 抢不到进程锁（已有心跳运行）
     */
    public function loop(int $intervalSec, string $source = 'cli_loop', ?callable $onTick = null): void
    {
        $intervalSec = max(1, min(300, $intervalSec));
        $wait = $this->settings->int('scheduler.exec.lock_wait_sec');
        $handle = $this->acquireProcessLock($wait);
        if ($handle === null) {
            throw new \RuntimeException('另一个心跳进程正在运行，抢锁失败');
        }

        $running = true;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, static function () use (&$running): void {
                    $running = false;
                });
            }
        }

        try {
            while ($running) {
                $started = microtime(true);
                $summary = $this->once($source);
                if ($onTick !== null) {
                    $onTick($summary);
                }
                $cost = microtime(true) - $started;
                $sleep = $intervalSec - $cost;
                if ($sleep > 0) {
                    usleep((int) round($sleep * 1000000));
                }
            }
        } finally {
            $this->releaseLock($handle);
        }
    }

    /** `--status` 输出：档位、触发源、存活、版本与锁持有者。 */
    public function status(): array
    {
        $heartbeat = $this->store->heartbeatRow('primary');
        $probe = (new SchedulerProbe($this->store, $this->settings))->probe();
        $startedAt = (string) ($heartbeat['started_at'] ?? '');
        $codeTime = @filemtime(__DIR__ . '/SchedulerService.php');
        $stale = $startedAt !== ''
            && $codeTime !== false
            && (int) strtotime($startedAt) < (int) $codeTime;

        return [
            'mode'                 => $this->settings->string('scheduler.mode'),
            'source'               => $this->settings->string('scheduler.mode') === 'pro'
                ? 'loop'
                : $this->settings->string('scheduler.lite.trigger'),
            'pid'                  => (int) ($heartbeat['pid'] ?? 0),
            'heartbeat_at'         => (string) ($heartbeat['heartbeat_at'] ?? ''),
            'alive'                => (bool) ($probe['heartbeat_alive'] ?? false),
            'code_version'         => self::CODE_VERSION,
            'running_code_version' => (string) ($heartbeat['code_version'] ?? ''),
            'stale_process'        => $stale,
            'lock_holder'          => $this->lockHolder($heartbeat),
            'effective_precision'  => (int) ($probe['precision'] ?? 0),
            'level'                => (string) ($probe['level'] ?? 'E3'),
            'checked_at'           => (string) ($probe['checked_at'] ?? ''),
        ];
    }

    /** 最近一次 tick 摘要（不含业务数据，仅运维观测）。 */
    public function lastTick(): array
    {
        $raw = $this->store->kvGet('scheduler.last_tick');
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** 抢进程锁：等待 $waitSec 秒，每 100ms 重试一次。 */
    private function acquireProcessLock(int $waitSec)
    {
        $file = (new HostBootstrap())->schedulerProcessLockFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        $deadline = microtime(true) + max(0, $waitSec);
        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                return null;
            }
            usleep(100000);
        }
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function lockHolder(?array $heartbeat): string
    {
        $file = (new HostBootstrap())->schedulerProcessLockFile();
        if (!is_file($file)) {
            return '';
        }
        $pid = (int) ($heartbeat['pid'] ?? 0);
        return $pid > 0 ? 'primary(pid=' . $pid . ')' : 'primary';
    }
}
