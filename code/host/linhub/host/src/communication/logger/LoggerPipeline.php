<?php

declare(strict_types=1);

namespace linhub\host\communication\logger;

use linhub\host\services\logging\Logging;

/**
 * 统一日志流水线（T6.2 / M4）
 *
 * 功能包日志与平台日志汇入同一条流水线，统一带 app_id 与 package_id，
 * 来源（platform/package）可区分，支持按 app_id/package_id/级别/关键词统一检索。
 *
 * 状态优先内存 + 文件（JSONL）持久化，验证不依赖外部服务；可选绑定底层 Logging
 * 契约将日志同时转发到原生日志实现以兼容 S1 契约消费。
 */
final class LoggerPipeline
{
    /** @var list<LogRecord> */
    private array $records = [];

    private string $storePath;

    private ?Logging $sink;

    public function __construct(string $storePath = '', ?Logging $sink = null)
    {
        $this->storePath = rtrim($storePath, '/\\');
        $this->sink = $sink;
    }

    /**
     * 写入一条日志。source 依据 packageId 自动推导：空串→platform，否则→package。
     *
     * @param string $appId     应用 id
     * @param string $packageId 功能包 id（空串表示平台自产）
     * @param string $level     级别 info/warning/error/debug
     * @param string $message   消息
     * @param array<string,mixed> $context 结构化上下文
     */
    public function write(string $appId, string $packageId, string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $source = $packageId !== '' ? 'package' : 'platform';
        $record = new LogRecord(
            $appId,
            $packageId,
            $level,
            $message,
            $context,
            $source,
            date('Y-m-d H:i:s'),
        );
        $this->records[] = $record;

        // 落盘（JSON 行），供统一持久化与文件回溯
        if ($this->storePath !== '') {
            @mkdir($this->storePath, 0755, true);
            $line = json_encode([
                'app_id' => $appId, 'package_id' => $packageId, 'level' => $level,
                'message' => $message, 'context' => $context, 'source' => $source,
                'created_at' => $record->createdAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($this->storePath . '/lg_pipeline.log', $line . "\n", FILE_APPEND);
        }

        // 可选：转发到 S1 Logging 契约
        if ($this->sink !== null) {
            $this->sink->info($message, array_merge(['app_id' => $appId, 'package_id' => $packageId], $context));
        }
    }

    /** 便捷 info 写入。 */
    public function info(string $appId, string $packageId, string $message, array $context = []): void
    {
        $this->write($appId, $packageId, 'info', $message, $context);
    }

    /** 便捷 error 写入。 */
    public function error(string $appId, string $packageId, string $message, array $context = []): void
    {
        $this->write($appId, $packageId, 'error', $message, $context);
    }

    /**
     * 统一检索（AND 组合过滤）。
     *
     * @return list<LogRecord>
     */
    public function search(?string $appId = null, ?string $packageId = null, ?string $level = null, ?string $keyword = null): array
    {
        $level = $level !== null ? strtolower($level) : null;
        return array_values(array_filter($this->records, function (LogRecord $r) use ($appId, $packageId, $level, $keyword) {
            if ($appId !== null && $r->appId !== $appId) {
                return false;
            }
            if ($packageId !== null && $r->packageId !== $packageId) {
                return false;
            }
            if ($level !== null && strtolower($r->level) !== $level) {
                return false;
            }
            if ($keyword !== null && !str_contains($r->message, $keyword)) {
                return false;
            }
            return true;
        }));
    }

    /** 全部记录。 */
    public function all(): array
    {
        return $this->records;
    }

    /** 记录总数。 */
    public function count(): int
    {
        return count($this->records);
    }

    /** 落盘文件路径（未开启持久化返回空串）。 */
    public function storeFile(): string
    {
        return $this->storePath !== '' ? $this->storePath . '/lg_pipeline.log' : '';
    }
}