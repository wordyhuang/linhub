<?php

declare(strict_types=1);

namespace linhub\host\rbac;

/**
 * 日志管理（T5.7 / B4）
 *
 * 日志模型（含 app_id / package_id / level / message / context / created_at / operator）：
 * 记录、检索（关键词 / 来源 / 时间 / level）、审计视图（可读列表）。
 * 以 JSON 文件存储，记录方法设计为可被后阶段（S6 宿主日志流水线）复用的宿主服务。
 */
final class AuditLog
{
    private const SECTION = 'logs';

    private JsonFileStore $store;

    public function __construct(string $file)
    {
        $this->store = new JsonFileStore($file);
    }

    /**
     * 记录一条日志。
     *
     * @param array<string, mixed> $context 结构化上下文（可选）
     * @return string 日志 id
     */
    public function record(
        string $level,
        string $message,
        array $context = [],
        string $appId = '',
        string $packageId = '',
        string $operator = '',
    ): string {
        $id = 'log_' . substr(md5((string) microtime(true) . $message), 0, 12);
        $entry = [
            'id'         => $id,
            'app_id'     => $appId,
            'package_id' => $packageId,
            'level'      => $level,
            'message'    => $message,
            'context'    => $context,
            'created_at' => date('Y-m-d H:i:s'),
            'operator'   => $operator,
        ];
        $logs = $this->store->section(self::SECTION);
        $logs[] = $entry;
        $this->store->putSection(self::SECTION, $logs);
        return $id;
    }

    /** 便捷：info 级别。 */
    public function info(string $message, array $context = [], string $packageId = '', string $operator = ''): string
    {
        return $this->record('info', $message, $context, 'linhub', $packageId, $operator);
    }

    /** 便捷：error 级别。 */
    public function error(string $message, array $context = [], string $packageId = '', string $operator = ''): string
    {
        return $this->record('error', $message, $context, 'linhub', $packageId, $operator);
    }

    /**
     * 检索日志。可组合过滤：关键词（匹配 message）、来源（package_id）、level、时间范围（created_at between）。
     *
     * @return list<array<string, mixed>>
     */
    public function search(?string $keyword = null, ?string $packageId = null, ?string $level = null, ?string $from = null, ?string $to = null): array
    {
        $logs = $this->store->section(self::SECTION);
        $kw   = $keyword !== null ? mb_strtolower($keyword) : null;
        return array_values(array_filter($logs, function (array $e) use ($kw, $packageId, $level, $from, $to): bool {
            if ($kw !== null && $kw !== '' && !str_contains(mb_strtolower((string) ($e['message'] ?? '')), $kw)) {
                return false;
            }
            if ($packageId !== null && $packageId !== '' && (string) ($e['package_id'] ?? '') !== $packageId) {
                return false;
            }
            if ($level !== null && $level !== '' && (string) ($e['level'] ?? '') !== $level) {
                return false;
            }
            $time = (string) ($e['created_at'] ?? '');
            if ($from !== null && $from !== '' && strcmp($time, $from) < 0) {
                return false;
            }
            if ($to !== null && $to !== '' && strcmp($time, $to) > 0) {
                return false;
            }
            return true;
        }));
    }

    /**
     * 审计视图：全部日志可读列表。
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->store->section(self::SECTION));
    }
}