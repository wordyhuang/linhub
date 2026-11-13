#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 异步任务托管独立入口（systemd / cron / schtasks 直接调用）。
 *
 * 用法与 think 控制台一致：
 *   php scheduler-heartbeat.php --once
 *   php scheduler-heartbeat.php --loop --interval=5
 *   php scheduler-heartbeat.php --probe --fresh
 *   php scheduler-heartbeat.php --status
 *   php scheduler-heartbeat.php --install --interval=1
 *
 * 退出码：0 成功、1 抢锁失败、2 参数错误、3 运行异常。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, '仅支持命令行运行' . PHP_EOL);
    exit(1);
}

$root = __DIR__;
require $root . '/vendor/autoload.php';

try {
    // 引导 App 以加载 .env 与数据库配置（PlatformStore 依赖 Env facade）
    $app = new \think\App($root);
    $app->initialize();
} catch (\Throwable $e) {
    fwrite(STDERR, '引导失败：' . $e->getMessage() . PHP_EOL);
    exit(3);
}

$args = [
    'once'     => false,
    'loop'     => false,
    'probe'    => false,
    'install'  => false,
    'status'   => false,
    'interval' => '1',
    'fresh'    => false,
];

$tokens = array_slice($argv, 1);
$index = 0;
$total = count($tokens);
while ($index < $total) {
    $token = (string) $tokens[$index];
    $index++;
    $raw = ltrim($token, '-');
    if ($raw === 'interval' || $raw === 'i') {
        $args['interval'] = (string) ($tokens[$index] ?? '');
        $index++;
        continue;
    }
    if (str_starts_with($raw, 'interval=')) {
        $args['interval'] = substr($raw, strlen('interval='));
        continue;
    }
    if (str_starts_with($raw, 'i=')) {
        $args['interval'] = substr($raw, strlen('i='));
        continue;
    }
    if (array_key_exists($raw, $args)) {
        $args[$raw] = true;
    }
}

$write = static function (string $line, bool $error = false): void {
    fwrite($error ? STDERR : STDOUT, $line . PHP_EOL);
};

exit(\linhub\host\console\SchedulerHeartbeatCommand::dispatch($args, $write));
