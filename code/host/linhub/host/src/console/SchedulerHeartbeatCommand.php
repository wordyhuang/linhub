<?php

declare(strict_types=1);

namespace linhub\host\console;

use linhub\host\services\scheduler\SchedulerHeartbeat;
use linhub\host\services\scheduler\SchedulerInstaller;
use linhub\host\services\scheduler\SchedulerProbe;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 异步任务托管心跳命令（`linhub:scheduler-heartbeat`）。
 *
 * 支持 --once / --loop / --probe / --install / --status；
 * 独立入口 code/host/scheduler-heartbeat.php 与 think 控制台共用 dispatch() 分发逻辑，
 * 避免 systemd / schtasks 依赖容器启动。
 */
final class SchedulerHeartbeatCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('linhub:scheduler-heartbeat')
            ->addOption('once', null, Option::VALUE_NONE, '执行一次 tick 后退出（cron / 计划任务）')
            ->addOption('loop', null, Option::VALUE_NONE, '常驻循环执行 tick（Pro 档）')
            ->addOption('probe', null, Option::VALUE_NONE, '输出 P-1~P-8 探测与 E0~E3 分级')
            ->addOption('install', null, Option::VALUE_NONE, '输出 systemd / cron / schtasks 安装命令文本')
            ->addOption('status', null, Option::VALUE_NONE, '输出心跳存活、版本与锁持有者')
            ->addOption('interval', 'i', Option::VALUE_REQUIRED, '循环间隔秒数（1~300）', '1')
            ->addOption('fresh', null, Option::VALUE_NONE, '--probe 跳过 60 秒缓存')
            ->setDescription('异步任务托管心跳与运维命令')
            ->setHelp(
                "示例：\n"
                . "  php think linhub:scheduler-heartbeat --once\n"
                . "  php think linhub:scheduler-heartbeat --loop --interval=5\n"
                . "  php think linhub:scheduler-heartbeat --probe --fresh\n"
                . "  php think linhub:scheduler-heartbeat --status\n"
                . "  php think linhub:scheduler-heartbeat --install --interval=1"
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        return self::dispatch([
            'once'     => (bool) $input->getOption('once'),
            'loop'     => (bool) $input->getOption('loop'),
            'probe'    => (bool) $input->getOption('probe'),
            'install'  => (bool) $input->getOption('install'),
            'status'   => (bool) $input->getOption('status'),
            'interval' => (string) $input->getOption('interval'),
            'fresh'    => (bool) $input->getOption('fresh'),
        ], static function (string $line, bool $error = false) use ($output): void {
            if ($error) {
                $output->error($line);
                return;
            }
            $output->writeln($line);
        });
    }

    /**
     * 参数分发（控制台与独立入口共用）。
     *
     * 退出码：0 成功、1 抢锁失败、2 参数错误、3 运行异常。
     *
     * @param array<string, mixed> $args
     * @param callable(string,bool=):void $write
     */
    public static function dispatch(array $args, callable $write): int
    {
        $modes = [];
        foreach (['once', 'loop', 'probe', 'install', 'status'] as $name) {
            if (!empty($args[$name])) {
                $modes[] = $name;
            }
        }
        if ($modes === []) {
            $write('请指定 --once / --loop / --probe / --install / --status 之一', true);
            return 2;
        }
        if (count($modes) > 1) {
            $write('一次只能使用一个动作：' . implode(' / ', $modes), true);
            return 2;
        }
        $mode = $modes[0];
        $interval = (string) ($args['interval'] ?? '1');

        try {
            if ($mode === 'install') {
                $data = (new SchedulerInstaller())->commands(PHP_OS_FAMILY, $interval);
                foreach ($data['items'] as $item) {
                    $write('== ' . $item['name'] . ' ==');
                    $write($item['text']);
                }
                $write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return 0;
            }

            if ($mode === 'probe') {
                $probe = (new SchedulerProbe())->probe((bool) ($args['fresh'] ?? false));
                $write((string) json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return 0;
            }

            $heartbeat = new SchedulerHeartbeat();

            if ($mode === 'status') {
                $status = $heartbeat->status();
                $status['last_tick'] = $heartbeat->lastTick();
                $write((string) json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return 0;
            }

            if ($mode === 'once') {
                $summary = $heartbeat->once('cli');
                $write((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return self::onceExitCode((string) ($summary['message'] ?? ''));
            }

            $seconds = (new SchedulerInstaller())->validateInterval($interval);
            $write('心跳循环启动，间隔 ' . $seconds . ' 秒（Ctrl+C 或 SIGTERM 退出）');
            $heartbeat->loop($seconds, 'cli_loop', static function (array $summary) use ($write): void {
                $write(sprintf(
                    '[%s] tick source=%s claimed=%d done=%d failed=%d cancelled=%d skipped=%d msg=%s',
                    date('c'),
                    (string) ($summary['source'] ?? ''),
                    (int) ($summary['claimed'] ?? 0),
                    (int) ($summary['done'] ?? 0),
                    (int) ($summary['failed'] ?? 0),
                    (int) ($summary['cancelled'] ?? 0),
                    (int) ($summary['skipped'] ?? 0),
                    (string) ($summary['message'] ?? '')
                ));
            });
            return 0;
        } catch (\InvalidArgumentException $e) {
            $write($e->getMessage(), true);
            return 2;
        } catch (\RuntimeException $e) {
            $write($e->getMessage(), true);
            return 1;
        } catch (\Throwable $e) {
            $write('运行异常：' . $e->getMessage(), true);
            return 3;
        }
    }

    /** 单次执行的退出码映射。 */
    private static function onceExitCode(string $message): int
    {
        if ($message === 'ok' || $message === '服务未启用') {
            return 0;
        }
        if (str_contains($message, '本次跳过')) {
            return 1;
        }
        return 3;
    }
}
