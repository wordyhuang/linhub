<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\scheduler\exception\SchedulerException;
use linhub\host\support\HostBootstrap;
use think\facade\Env;

/**
 * 调度器配置（需求 7.2 的 42 项，平铺键值存 data/platform/scheduler_setting.json）。
 *
 * 读取顺序：默认值 → 文件覆盖 → env 硬顶（SCHEDULER_MAX_CONCURRENT / SCHEDULER_MAX_TIMEOUT_SEC）。
 * 保存时按 RANGES 与枚举白名单归一化，非法值抛 SchedulerException，不做静默截断。
 */
final class SchedulerSettings
{
    public const DEFAULTS = [
        'scheduler.enabled'                          => true,
        'scheduler.exec.global_max_concurrent'       => 8,
        'scheduler.exec.package_max_concurrent'      => 3,
        'scheduler.exec.heartbeat_batch'             => 10,
        'scheduler.exec.package_batch_per_tick'      => 2,
        'scheduler.exec.heartbeat_budget_sec'        => 30,
        'scheduler.exec.heartbeat_interval_sec'      => 5,
        'scheduler.exec.lock_wait_sec'               => 5,
        'scheduler.task.timeout_sec'                 => 600,
        'scheduler.task.lease_sec'                   => 90,
        'scheduler.task.heartbeat_hint_sec'          => 30,
        'scheduler.task.stale_grace_sec'             => 30,
        'scheduler.queue.max_queued_per_package'     => 50,
        'scheduler.queue.max_queued_global'          => 500,
        'scheduler.queue.overflow_policy'            => 'reject',
        'scheduler.queue.max_runs_per_package'       => 500,
        'scheduler.queue.terminal_retention_days'    => 7,
        'scheduler.quota.max_payload_bytes'          => 1048576,
        'scheduler.quota.max_result_bytes'           => 1048576,
        'scheduler.quota.max_log_lines'              => 200,
        'scheduler.quota.max_periodic_defs_per_package' => 20,
        'scheduler.quota.min_interval_sec'           => 1,
        'scheduler.periodic.fail_pause_after'        => 3,
        'scheduler.periodic.max_runs_per_def'        => 100,
        'scheduler.periodic.failed_retention_days'   => 7,
        'scheduler.periodic.missed_policy'           => 'skip',
        'scheduler.circuit.enabled'                  => true,
        'scheduler.circuit.window_sec'               => 300,
        'scheduler.circuit.fail_rate_threshold'      => 0.5,
        'scheduler.circuit.min_samples'              => 5,
        'scheduler.circuit.cooldown_sec'             => 300,
        'scheduler.alert.enabled'                    => true,
        'scheduler.alert.stale_task_threshold'       => 5,
        'scheduler.alert.fail_rate_threshold'        => 0.3,
        'scheduler.alert.suppress_sec'               => 1800,
        'scheduler.alert.channel'                    => 'inbox',
        'scheduler.notify.default_on_done'           => true,
        'scheduler.idempotency.enabled'              => true,
        'scheduler.idempotency.ttl_sec'              => 86400,
        'scheduler.mode'                             => 'lite',
        'scheduler.lite.trigger'                     => 'auto',
        'scheduler.mode.switch_drain_sec'            => 60,
    ];

    /** 整数范围 [min, max]。 */
    private const RANGES = [
        'scheduler.exec.global_max_concurrent'       => [1, 64],
        'scheduler.exec.package_max_concurrent'      => [1, 20],
        'scheduler.exec.heartbeat_batch'             => [1, 50],
        'scheduler.exec.package_batch_per_tick'      => [1, 10],
        'scheduler.exec.heartbeat_budget_sec'        => [1, 120],
        'scheduler.exec.heartbeat_interval_sec'      => [1, 3600],
        'scheduler.exec.lock_wait_sec'               => [1, 30],
        'scheduler.task.timeout_sec'                 => [60, 1800],
        'scheduler.task.lease_sec'                   => [30, 600],
        'scheduler.task.heartbeat_hint_sec'          => [10, 300],
        'scheduler.task.stale_grace_sec'             => [0, 120],
        'scheduler.queue.max_queued_per_package'     => [10, 500],
        'scheduler.queue.max_queued_global'          => [50, 5000],
        'scheduler.queue.max_runs_per_package'       => [100, 5000],
        'scheduler.queue.terminal_retention_days'    => [1, 90],
        'scheduler.quota.max_payload_bytes'          => [65536, 4194304],
        'scheduler.quota.max_result_bytes'           => [65536, 4194304],
        'scheduler.quota.max_log_lines'              => [50, 1000],
        'scheduler.quota.max_periodic_defs_per_package' => [1, 100],
        'scheduler.quota.min_interval_sec'           => [1, 3600],
        'scheduler.periodic.fail_pause_after'        => [1, 10],
        'scheduler.periodic.max_runs_per_def'        => [20, 1000],
        'scheduler.periodic.failed_retention_days'   => [1, 90],
        'scheduler.circuit.window_sec'               => [60, 1800],
        'scheduler.circuit.min_samples'              => [3, 50],
        'scheduler.circuit.cooldown_sec'             => [60, 3600],
        'scheduler.alert.stale_task_threshold'       => [1, 100],
        'scheduler.alert.suppress_sec'               => [300, 86400],
        'scheduler.idempotency.ttl_sec'              => [60, 604800],
        'scheduler.mode.switch_drain_sec'            => [10, 600],
    ];

    /** 浮点范围 [min, max]。 */
    private const FLOAT_RANGES = [
        'scheduler.circuit.fail_rate_threshold' => [0.1, 1.0],
        'scheduler.alert.fail_rate_threshold'   => [0.1, 1.0],
    ];

    /** 布尔项。 */
    private const BOOLS = [
        'scheduler.enabled',
        'scheduler.circuit.enabled',
        'scheduler.alert.enabled',
        'scheduler.notify.default_on_done',
        'scheduler.idempotency.enabled',
    ];

    /** 枚举项白名单。 */
    private const ENUMS = [
        'scheduler.queue.overflow_policy' => ['reject', 'allow'],
        'scheduler.periodic.missed_policy' => ['skip', 'catchup_once'],
        'scheduler.alert.channel'          => ['inbox', 'inbox+log'],
        'scheduler.mode'                   => ['lite', 'pro'],
        'scheduler.lite.trigger'           => ['auto', 'cron_once', 'request_tail'],
    ];

    /** 配置项中文名称（管理端设置页展示用，与 DEFAULTS 同序）。 */
    private const TITLES = [
        'scheduler.enabled'                             => '调度总开关',
        'scheduler.exec.global_max_concurrent'          => '全局最大并发数',
        'scheduler.exec.package_max_concurrent'         => '单包最大并发数',
        'scheduler.exec.heartbeat_batch'                => '心跳单批扫描任务数',
        'scheduler.exec.package_batch_per_tick'         => '每轮每包取任务数',
        'scheduler.exec.heartbeat_budget_sec'           => '心跳单轮时间预算（秒）',
        'scheduler.exec.heartbeat_interval_sec'         => '心跳间隔（秒）',
        'scheduler.exec.lock_wait_sec'                  => '调度锁等待超时（秒）',
        'scheduler.task.timeout_sec'                    => '任务执行超时（秒）',
        'scheduler.task.lease_sec'                      => '任务租约时长（秒）',
        'scheduler.task.heartbeat_hint_sec'             => '任务心跳提示间隔（秒）',
        'scheduler.task.stale_grace_sec'                => '僵尸任务宽限期（秒）',
        'scheduler.queue.max_queued_per_package'        => '单包排队上限',
        'scheduler.queue.max_queued_global'             => '全局排队上限',
        'scheduler.queue.overflow_policy'               => '队列溢出策略',
        'scheduler.queue.max_runs_per_package'          => '单包运行记录保留上限',
        'scheduler.queue.terminal_retention_days'       => '终态实例保留天数',
        'scheduler.quota.max_payload_bytes'             => '任务载荷上限（字节）',
        'scheduler.quota.max_result_bytes'              => '任务结果上限（字节）',
        'scheduler.quota.max_log_lines'                 => '任务日志行数上限',
        'scheduler.quota.max_periodic_defs_per_package' => '单包周期定义数上限',
        'scheduler.quota.min_interval_sec'              => '周期任务最小间隔（秒）',
        'scheduler.periodic.fail_pause_after'           => '连续失败自动暂停阈值（次）',
        'scheduler.periodic.max_runs_per_def'           => '单定义运行记录保留上限',
        'scheduler.periodic.failed_retention_days'      => '失败实例保留天数',
        'scheduler.periodic.missed_policy'              => '错过触发补偿策略',
        'scheduler.circuit.enabled'                     => '熔断器开关',
        'scheduler.circuit.window_sec'                  => '熔断统计窗口（秒）',
        'scheduler.circuit.fail_rate_threshold'         => '熔断失败率阈值',
        'scheduler.circuit.min_samples'                 => '熔断最小样本数',
        'scheduler.circuit.cooldown_sec'                => '熔断冷却时长（秒）',
        'scheduler.alert.enabled'                       => '告警开关',
        'scheduler.alert.stale_task_threshold'          => '僵尸任务告警阈值（个）',
        'scheduler.alert.fail_rate_threshold'           => '告警失败率阈值',
        'scheduler.alert.suppress_sec'                  => '同类告警抑制间隔（秒）',
        'scheduler.alert.channel'                       => '告警投递渠道',
        'scheduler.notify.default_on_done'              => '任务完成默认通知',
        'scheduler.idempotency.enabled'                 => '幂等去重开关',
        'scheduler.idempotency.ttl_sec'                 => '幂等键保留时长（秒）',
        'scheduler.mode'                                => '调度模式',
        'scheduler.lite.trigger'                        => 'Lite 档触发方式',
        'scheduler.mode.switch_drain_sec'               => '模式切换排水等待（秒）',
    ];

    private string $file;

    private ?array $cache = null;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? (new HostBootstrap())->schedulerSettingFile();
    }

    /** 全部 42 项生效值（含 env 硬顶）。 */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = [];
        if (is_file($this->file)) {
            $decoded = json_decode((string) file_get_contents($this->file), true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        $values = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $stored)) {
                $normalized = $this->normalize($key, $stored[$key], false);
                if ($normalized !== null) {
                    $values[$key] = $normalized;
                }
            }
        }
        $envConcurrent = (int) Env::get('SCHEDULER_MAX_CONCURRENT', 64);
        if ($envConcurrent > 0 && $values['scheduler.exec.global_max_concurrent'] > $envConcurrent) {
            $values['scheduler.exec.global_max_concurrent'] = $envConcurrent;
        }
        $envTimeout = (int) Env::get('SCHEDULER_MAX_TIMEOUT_SEC', 1800);
        if ($envTimeout > 0 && $values['scheduler.task.timeout_sec'] > $envTimeout) {
            $values['scheduler.task.timeout_sec'] = $envTimeout;
        }
        $this->cache = $values;
        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, 0);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key, 0.0);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key, '');
    }

    /**
     * 批量保存（部分更新），返回保存后的全部生效值。
     *
     * @throws SchedulerException 任一键非法时整体拒绝
     */
    public function save(array $patch): array
    {
        $current = $this->all();
        $merged = $current;
        foreach ($patch as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $normalized = $this->normalize((string) $key, $value, true);
            if ($normalized === null) {
                throw new SchedulerException('配置项取值非法：' . $key);
            }
            $merged[$key] = $normalized;
        }
        $this->persist($merged);
        $this->cache = null;
        return $this->all();
    }

    /** 恢复默认值。 */
    public function reset(): array
    {
        $this->persist(self::DEFAULTS);
        $this->cache = null;
        return $this->all();
    }

    /** 供管理端渲染的配置表结构（含默认值、范围、枚举）。 */
    public function schema(): array
    {
        $rows = [];
        foreach (self::DEFAULTS as $key => $default) {
            $rows[] = [
                'key'     => $key,
                'title'   => self::TITLES[$key] ?? $key,
                'default' => $default,
                'type'    => in_array($key, self::BOOLS, true) ? 'bool'
                    : (isset(self::ENUMS[$key]) ? 'enum'
                        : (isset(self::FLOAT_RANGES[$key]) ? 'float' : 'int')),
                'min'     => self::RANGES[$key][0] ?? (self::FLOAT_RANGES[$key][0] ?? null),
                'max'     => self::RANGES[$key][1] ?? (self::FLOAT_RANGES[$key][1] ?? null),
                'enum'    => self::ENUMS[$key] ?? null,
            ];
        }
        return $rows;
    }

    /** 归一化；strict=true 时非法值返回 null，false 时非法值也返回 null（由调用方跳过）。 */
    private function normalize(string $key, mixed $value, bool $strict): int|float|bool|string|null
    {
        if (in_array($key, self::BOOLS, true)) {
            if (is_bool($value)) {
                return $value;
            }
            $text = strtolower(trim((string) $value));
            return in_array($text, ['1', 'true', 'on', 'yes'], true);
        }
        if (isset(self::ENUMS[$key])) {
            $text = trim((string) $value);
            return in_array($text, self::ENUMS[$key], true) ? $text : null;
        }
        if (isset(self::FLOAT_RANGES[$key])) {
            if (!is_numeric($value)) {
                return null;
            }
            $number = (float) $value;
            [$min, $max] = self::FLOAT_RANGES[$key];
            return $number >= $min && $number <= $max ? $number : null;
        }
        if (isset(self::RANGES[$key])) {
            if (!is_numeric($value)) {
                return null;
            }
            $number = (int) $value;
            [$min, $max] = self::RANGES[$key];
            return $number >= $min && $number <= $max ? $number : null;
        }
        return $strict ? (is_scalar($value) ? $value : null) : null;
    }

    private function persist(array $values): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SchedulerException('无法创建配置目录：' . $dir);
        }
        $payload = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $payload[$key] = $values[$key] ?? self::DEFAULTS[$key];
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new SchedulerException('配置序列化失败');
        }
        $temp = $this->file . '.tmp';
        if (file_put_contents($temp, $json) === false) {
            throw new SchedulerException('配置写入失败：' . $this->file);
        }
        if (!rename($temp, $this->file)) {
            @unlink($temp);
            throw new SchedulerException('配置落盘失败：' . $this->file);
        }
    }
}
