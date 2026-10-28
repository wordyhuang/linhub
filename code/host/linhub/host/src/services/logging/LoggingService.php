<?php

declare(strict_types=1);

namespace linhub\host\services\logging;

/**
 * 日志服务实现（宿主内部可替换，T1.7）。
 *
 * 现阶段落盘到 runtime 日志；后续可扩展统一流水线（T6.2）。
 */
class LoggingService implements Logging
{
    public function __construct(private readonly string $logPath = '')
    {
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s | %s | %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $path = $this->logPath !== '' ? $this->logPath : runtime_path() . 'log' . DIRECTORY_SEPARATOR . date('Ym') . DIRECTORY_SEPARATOR;
        @mkdir($path, 0755, true);
        @file_put_contents($path . 'host.log', $line, FILE_APPEND);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }
}