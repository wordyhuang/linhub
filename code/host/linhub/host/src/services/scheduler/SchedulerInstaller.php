<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * 运维安装命令生成器（`--install`）。
 *
 * 只拼接文本、不执行任何命令；interval 在建模板前严格校验，输入不进入 shell 字符串执行路径。
 */
final class SchedulerInstaller
{
    /**
     * 校验 `--interval`，返回合法秒数。
     *
     * @throws \InvalidArgumentException 非数字、越界或含任意非数字字符
     */
    public function validateInterval(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw new \InvalidArgumentException('--interval 必须为 1~300 的整数');
        }
        $seconds = (int) $raw;
        if ($seconds < 1 || $seconds > 300) {
            throw new \InvalidArgumentException('--interval 超出范围（1~300 秒）');
        }
        return $seconds;
    }

    /**
     * 按操作系统生成安装命令文本。
     *
     * @return array{os:string,php:string,entry:string,items:array<int,array{name:string,text:string}>}
     */
    public function commands(string $os, string $interval = '1'): array
    {
        $seconds = $this->validateInterval($interval);
        $php = $this->phpBinary();
        $entry = $this->entryFile();
        $windows = stripos($os, 'windows') !== false;

        if ($windows) {
            $items = [
                [
                    'name' => 'schtasks',
                    'text' => sprintf(
                        'schtasks /create /tn LinHubSchedulerHeartbeat /tr "\"%s\" \"%s\" --once" /sc minute /mo 1 /f',
                        $php,
                        $entry
                    ),
                ],
                [
                    'name' => 'loop',
                    'text' => sprintf('"%s" "%s" --loop --interval=%d', $php, $entry, $seconds),
                ],
            ];
        } else {
            $unit = implode(PHP_EOL, [
                '[Unit]',
                'Description=LinHub Scheduler Heartbeat',
                'After=network.target',
                '',
                '[Service]',
                'Type=simple',
                'WorkingDirectory=' . $this->hostRoot(),
                sprintf('ExecStart="%s" "%s" --loop --interval=%d', $php, $entry, $seconds),
                'Restart=always',
                'RestartSec=5',
                'RuntimeMaxSec=86400',
                '',
                '[Install]',
                'WantedBy=multi-user.target',
            ]);
            $items = [
                [
                    'name' => 'systemd',
                    'text' => "写入 /etc/systemd/system/linhub-scheduler-heartbeat.service：" . PHP_EOL . $unit,
                ],
                [
                    'name' => 'cron',
                    'text' => sprintf('*/%d * * * * cd %s && "%s" "%s" --once', $seconds, $this->hostRoot(), $php, $entry),
                ],
                [
                    'name' => 'loop',
                    'text' => sprintf('"%s" "%s" --loop --interval=%d', $php, $entry, $seconds),
                ],
            ];
        }

        return [
            'os'    => $windows ? 'Windows' : 'Linux',
            'php'   => $php,
            'entry' => $entry,
            'items' => $items,
        ];
    }

    /** 宿主根目录：code/host。 */
    public function hostRoot(): string
    {
        if (function_exists('root_path')) {
            return rtrim(str_replace('\\', '/', (string) root_path()), '/');
        }
        return str_replace('\\', '/', dirname(__DIR__, 5));
    }

    /** 独立入口脚本：code/host/scheduler-heartbeat.php。 */
    public function entryFile(): string
    {
        return $this->hostRoot() . '/scheduler-heartbeat.php';
    }

    private function phpBinary(): string
    {
        return PHP_BINARY !== '' ? PHP_BINARY : 'php';
    }
}
