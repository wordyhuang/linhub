<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\support\PlatformStore;
use linhub\host\support\HostBootstrap;

/**
 * 环境探测（P-1~P-8）与运行档位分级（E0~E3）。
 *
 * 供 `--probe` 命令与设置页「环境自检」共用；结果缓存 60 秒（task_kv.probe_cache），
 * 强制刷新用 `probe(true)`。探测全部做错误抑制，任何单项失败只降级为 false，不抛异常。
 */
final class SchedulerProbe
{
    private const CACHE_KEY = 'scheduler.probe_cache';

    private const CACHE_TTL = 60;

    private PlatformStore $store;

    private SchedulerSettings $settings;

    public function __construct(?PlatformStore $store = null, ?SchedulerSettings $settings = null)
    {
        $this->store = $store ?? new PlatformStore();
        $this->settings = $settings ?? new SchedulerSettings();
    }

    /**
     * 执行探测；$fresh=true 跳过 60 秒缓存。
     *
     * @return array<string, mixed>
     */
    public function probe(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->store->kvGet(self::CACHE_KEY);
            if ($cached !== null && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['checked_at'])
                    && time() - (int) strtotime((string) $decoded['checked_at']) < self::CACHE_TTL) {
                    return $decoded;
                }
            }
        }

        $result = [
            'os'              => PHP_OS_FAMILY,
            'sapi'            => PHP_SAPI,
            'finish_request'  => $this->finishRequestAvailable(),
            'process'         => $this->processAvailable(),
            'schedulers'      => $this->schedulers(),
            'heartbeat_alive' => false,
            'heartbeat_at'    => '',
            'precision'       => $this->effectivePrecision(),
            'local_fs'        => $this->localFilesystem(),
            'writable'        => is_writable((new HostBootstrap())->dataRoot()),
            'mode'            => $this->settings->string('scheduler.mode'),
            'trigger'         => $this->settings->string('scheduler.lite.trigger'),
            'enabled'         => $this->settings->bool('scheduler.enabled'),
            'checked_at'      => date('c'),
        ];

        $heartbeat = $this->store->heartbeatRow('primary');
        if ($heartbeat !== null && (string) ($heartbeat['heartbeat_at'] ?? '') !== '') {
            $result['heartbeat_at'] = (string) $heartbeat['heartbeat_at'];
            $window = 3 * max(1, $this->settings->int('scheduler.exec.heartbeat_interval_sec'));
            $age = time() - (int) strtotime($result['heartbeat_at']);
            $result['heartbeat_alive'] = $age >= 0 && $age < $window;
        }

        $result['level'] = $this->level($result);
        $this->store->kvPut(self::CACHE_KEY, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    /** E3 无心跳 / E2 仅分钟级 / E1 无 fastcgi 收尾 / E0 健全。 */
    public function level(array $probe): string
    {
        if (empty($probe['heartbeat_alive'])) {
            return 'E3';
        }
        if (($probe['mode'] ?? 'lite') !== 'pro' && ($probe['schedulers'] ?? []) === []) {
            return 'E2';
        }
        return empty($probe['finish_request']) ? 'E1' : 'E0';
    }

    /** P-3：fastcgi_finish_request 存在且未被 disable_functions 禁用。 */
    private function finishRequestAvailable(): bool
    {
        if (!function_exists('fastcgi_finish_request')) {
            return false;
        }
        return !$this->disabled('fastcgi_finish_request');
    }

    /** P-4：PHP_BINARY 非空，proc_open / exec 至少一个可用。 */
    private function processAvailable(): bool
    {
        if (PHP_BINARY === '') {
            return false;
        }
        return (function_exists('proc_open') && !$this->disabled('proc_open'))
            || (function_exists('exec') && !$this->disabled('exec'));
    }

    private function disabled(string $function): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array(strtolower($function), array_map('strtolower', $list), true);
    }

    /** P-5：探测可用的系统级调度器（全部错误抑制 + 结果只看退出码）。 */
    private function schedulers(): array
    {
        $found = [];
        $checks = [
            'systemd' => 'systemctl --version',
            'cron'    => 'crontab -l',
            'schtasks' => 'schtasks /query /?',
        ];
        foreach ($checks as $name => $command) {
            $output = [];
            $code = 1;
            @exec($command . ' 2>&1', $output, $code);
            // crontab -l 在无任务时也返回 0；返回 1 视为不可用。
            if ($code === 0) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /** P-7：数据目录与根目录处于同一设备（粗略判定为本地盘）。 */
    private function localFilesystem(): bool
    {
        $data = (new HostBootstrap())->dataRoot();
        if (!is_dir($data)) {
            return false;
        }
        $dataDev = @stat($data);
        $rootDev = @stat(dirname($data));
        if (!is_array($dataDev) || !is_array($rootDev)) {
            return false;
        }
        return ($dataDev['dev'] ?? -1) === ($rootDev['dev'] ?? -2);
    }

    /** 有效触发精度（秒）：Lite 请求尾 10s、Lite cron 60s、Pro 取心跳间隔。 */
    public function effectivePrecision(): int
    {
        if ($this->settings->string('scheduler.mode') === 'pro') {
            return max(1, $this->settings->int('scheduler.exec.heartbeat_interval_sec'));
        }
        return $this->settings->string('scheduler.lite.trigger') === 'cron_once' ? 60 : 10;
    }

    /** 安装命令（供 `--install` 与管理端展示，只生成不执行）。 */
    public function installCommands(string $interval = '1'): array
    {
        return (new SchedulerInstaller())->commands(PHP_OS_FAMILY, $interval);
    }
}
