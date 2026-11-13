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
    /** 本请求内已确认存在的目录，避免每条日志重复 mkdir。 */
    private static array $ensuredDirs = [];

    public function __construct(private readonly string $logPath = '')
    {
    }

    /** 运行日志根目录（工作台巡检与写盘共用同一口径）。 */
    public static function defaultLogDir(): string
    {
        return runtime_path() . 'log' . DIRECTORY_SEPARATOR;
    }

    private function write(string $level, string $message, array $context): void
    {
        // 日志设置：总开关关闭或低于最低记录级别时不落盘（缺省 enabled=1 / debug，保持历史行为）
        $settings = LogSettingService::shared();
        if (!$settings->enabled() || !$settings->passes($level)) {
            return;
        }
        $line = sprintf(
            "[%s] %s | %s | %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        // 统一按月归档：{根目录}/YYYYMM/host.log（注入路径与缺省路径同口径）
        $root = $this->logPath !== '' ? $this->logPath : self::defaultLogDir();
        $path = rtrim(str_replace('\\', '/', $root), '/') . '/' . date('Ym') . '/';
        if (!isset(self::$ensuredDirs[$path])) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            self::$ensuredDirs[$path] = true;
        }
        // LOCK_EX 保证并发写入时整行不撕裂
        @file_put_contents($path . 'host.log', $line, FILE_APPEND | LOCK_EX);
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