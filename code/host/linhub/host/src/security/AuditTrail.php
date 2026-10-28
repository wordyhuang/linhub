<?php

declare(strict_types=1);

namespace linhub\host\security;

use linhub\host\rbac\JsonFileStore;

/**
 * 全程审计贯通（T7.3 / B4 / R5 / Q）
 *
 * 将生命周期、迁移、备份、超管操作统一为同一结构的一条审计记录：
 * operator（执行人）、action、target、created_at、context。
 * 基于 JSON 文件原子写（复用 JsonFileStore），提供记录与按 action/operator/时间检索。
 */
final class AuditTrail
{
    private const SECTION = 'audit_trail';

    private JsonFileStore $store;

    public function __construct(string $file)
    {
        $this->store = new JsonFileStore($file);
    }

    /**
     * 记录一条审计。
     *
     * @param array<string, mixed> $context 结构化上下文（可选）
     * @return string 审计 id
     */
    public function log(string $action, string $target, string $operator = '', array $context = []): string
    {
        $id = 'at_' . substr(md5((string) microtime(true) . $action . $target), 0, 14);
        $entry = [
            'id'         => $id,
            'operator'   => $operator,
            'action'     => $action,
            'target'     => $target,
            'created_at' => date('Y-m-d H:i:s'),
            'context'    => $context,
        ];
        $list = $this->store->section(self::SECTION);
        $list[] = $entry;
        $this->store->putSection(self::SECTION, $list);
        return $id;
    }

    /**
     * 检索审计，可组合过滤 action/operator/时间范围。
     *
     * @return list<array<string, mixed>>
     */
    public function query(?string $action = null, ?string $operator = null, ?string $from = null, ?string $to = null): array
    {
        $list = $this->store->section(self::SECTION);
        return array_values(array_filter($list, function (array $e) use ($action, $operator, $from, $to): bool {
            if ($action !== null && $action !== '' && (string) ($e['action'] ?? '') !== $action) {
                return false;
            }
            if ($operator !== null && $operator !== '' && (string) ($e['operator'] ?? '') !== $operator) {
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

    /** 全部审计记录。 */
    public function all(): array
    {
        return array_values($this->store->section(self::SECTION));
    }
}