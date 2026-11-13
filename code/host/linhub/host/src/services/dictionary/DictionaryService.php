<?php

declare(strict_types=1);

namespace linhub\host\services\dictionary;

use linhub\host\rbac\AuditLog;
use linhub\host\services\support\PlatformStore;

/**
 * 数据字典服务实现（DD-1 ~ DD-9）。
 *
 * 存储于 lg_mgt_dict_item：namespace 为归属功能包短名，dict_key 为业务 key；
 * value 标量按 string/int/float/bool 记录类型，数组/对象以 JSON 字符串 + json 类型持久化。
 * 无参构造（自读 env 建 PlatformStore），满足 ServiceInjector 的 app->make()。
 *
 * 审计留痕：put（新增/修改）与 remove（删除）自动写平台统一操作日志
 * （data/log/audit.json，管理端「审计日志-操作日志」Tab 可检索），记录动作、
 * 命名空间、key、变更前后值摘要与调用方来源；日志写失败不阻断主操作。
 */
final class DictionaryService implements Dictionary
{
    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    private PlatformStore $store;

    private ?AuditLog $audit = null;

    public function put(string $package, string $key, mixed $value, array $meta = []): array
    {
        $this->assertKey($key);
        $namespace = $this->normNamespace($package);
        $this->store->ensureDictTables();

        [$stored, $type] = $this->encodeValue($value);
        $isPublic = !empty($meta['is_public']) ? 1 : 0;
        $remark = (string) ($meta['remark'] ?? '');
        $now = date('c');

        $existing = $this->rawEntry($namespace, $key);
        if ($existing === null) {
            $this->store->db()->exec(
                'INSERT INTO ' . PlatformStore::DICT_TABLE
                . ' (namespace, dict_key, value, value_type, owner_pkg, is_public, remark, created_at, updated_at) VALUES ('
                . $this->store->quote($namespace) . ', '
                . $this->store->quote($key) . ', '
                . $this->store->quote($stored) . ', '
                . $this->store->quote($type) . ', '
                . $this->store->quote($namespace) . ', '
                . $isPublic . ', '
                . $this->store->quote($remark) . ', '
                . $this->store->quote($now) . ', '
                . $this->store->quote($now) . ')'
            );
        } else {
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::DICT_TABLE
                . ' SET value=' . $this->store->quote($stored)
                . ', value_type=' . $this->store->quote($type)
                . ', is_public=' . $isPublic
                . ', remark=' . $this->store->quote($remark)
                . ', updated_at=' . $this->store->quote($now)
                . ' WHERE id=' . (int) $existing['id']
            );
        }

        $this->logChange($existing === null ? 'create' : 'update', $namespace, $key, $type, $stored, $existing, $remark);
        return $this->decorate($this->rawEntry($namespace, $key));
    }

    public function get(string $package, string $namespace, string $key, mixed $default = null, bool $asAdmin = false): mixed
    {
        $entry = $this->entry($package, $namespace, $key, $asAdmin);
        return $entry === null ? $default : $entry['value_parsed'];
    }

    public function remove(string $package, string $namespace, string $key, bool $asAdmin = false): bool
    {
        $namespace = $this->normNamespace($namespace);
        $this->store->ensureDictTables();
        $existing = $this->rawEntry($namespace, $key);
        if ($existing === null) {
            return false;
        }
        if (!$asAdmin && $existing['owner_pkg'] !== $this->normNamespace($package)) {
            throw new DictionaryDenied('只有托管功能包可删除该字典数据');
        }
        $this->store->db()->exec('DELETE FROM ' . PlatformStore::DICT_TABLE . ' WHERE id=' . (int) $existing['id']);
        $this->logChange('delete', $namespace, $key, (string) $existing['value_type'], (string) $existing['value'], $existing, (string) ($existing['remark'] ?? ''));
        return true;
    }

    public function search(string $package, array $filter = [], int $page = 1, int $size = 20, bool $asAdmin = false): array
    {
        $this->store->ensureDictTables();
        $where = [];
        if (($filter['namespace'] ?? '') !== '') {
            $where[] = 'namespace=' . $this->store->quote($this->normNamespace((string) $filter['namespace']));
        }
        if (($filter['keyword'] ?? '') !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filter['keyword']) . '%';
            $where[] = 'dict_key LIKE ' . $this->store->quote($kw);
        }
        if (($filter['is_public'] ?? '') !== '') {
            $where[] = 'is_public=' . ((int) $filter['is_public'] ? 1 : 0);
        }
        if (!$asAdmin) {
            // 功能包视角：本包全部可见，他包仅公开可见。
            $where[] = '(namespace=' . $this->store->quote($this->normNamespace($package))
                . ' OR is_public=1)';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $totalRows = $this->store->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::DICT_TABLE . $whereSql);
        $total = (int) ($totalRows[0]['c'] ?? 0);
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::DICT_TABLE . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map(fn ($r): array => $this->decorate($r), $rows),
        ];
    }

    public function entry(string $package, string $namespace, string $key, bool $asAdmin = false): ?array
    {
        $this->store->ensureDictTables();
        $row = $this->rawEntry($this->normNamespace($namespace), $key);
        if ($row === null) {
            return null;
        }
        if (!$asAdmin
            && $row['owner_pkg'] !== $this->normNamespace($package)
            && (int) $row['is_public'] !== 1) {
            return null;
        }
        return $this->decorate($row);
    }

    public function stats(): array
    {
        $this->store->ensureDictTables();
        $totalRows = $this->store->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::DICT_TABLE);
        $byNs = $this->store->db()->query(
            'SELECT namespace, COUNT(*) AS c FROM ' . PlatformStore::DICT_TABLE . ' GROUP BY namespace ORDER BY c DESC'
        );
        return [
            'total'       => (int) ($totalRows[0]['c'] ?? 0),
            'namespaces'  => array_map(static fn ($r): array => [
                'namespace' => (string) $r['namespace'],
                'count'     => (int) $r['c'],
            ], $byNs),
        ];
    }

    private function rawEntry(string $namespace, string $key): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::DICT_TABLE
            . ' WHERE namespace=' . $this->store->quote($namespace)
            . ' AND dict_key=' . $this->store->quote($key) . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function decorate(array $row): array
    {
        $row['value_parsed'] = $this->decodeValue((string) $row['value'], (string) $row['value_type']);
        $row['is_public'] = (int) ($row['is_public'] ?? 0);
        return $row;
    }

    /**
     * @return array{0:string,1:string} [存储字符串, 类型标识]
     */
    private function encodeValue(mixed $value): array
    {
        if (is_array($value)) {
            return [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json'];
        }
        if (is_bool($value)) {
            return [$value ? '1' : '0', 'bool'];
        }
        if (is_int($value)) {
            return [(string) $value, 'int'];
        }
        if (is_float($value)) {
            return [(string) $value, 'float'];
        }
        if ($value === null) {
            return ['', 'null'];
        }
        return [(string) $value, 'string'];
    }

    private function decodeValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'json'   => json_decode($raw, true),
            'int'    => (int) $raw,
            'float'  => (float) $raw,
            'bool'   => $raw === '1',
            'null'   => null,
            default  => $raw,
        };
    }

    private function normNamespace(string $package): string
    {
        $ns = trim($package);
        if ($ns === '') {
            throw new \InvalidArgumentException('功能包命名空间不能为空');
        }
        return $ns;
    }

    /* ── 审计留痕（数据字典变更 → 平台统一操作日志 audit.json） ── */

    /** 惰性获取平台操作日志实例（与 HostBootstrap::auditLog() 同一文件，管理端审计页直读）。 */
    private function auditLog(): AuditLog
    {
        return $this->audit ??= new AuditLog(
            str_replace('\\', '/', dirname(__DIR__, 5)) . '/data/log/audit.json'
        );
    }

    /** 调用方功能包短名（调试回溯定位 plugins/<short>，与 RecycleBinService 同法）。 */
    private function callerFromTrace(): string
    {
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__, 6) . '/plugins'), '/') . '/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || strpos($file, $root) !== 0) {
                continue;
            }
            $short = explode('/', substr($file, strlen($root)), 2)[0];
            if ($short !== '' && preg_match('/^[A-Za-z0-9_.\-]+$/', $short)) {
                return $short;
            }
        }
        return '';
    }

    /**
     * 写入一条变更日志。动作映射：create 新增 / update 修改 / delete 删除；
     * 记录变更前后值摘要（超 300 字符截断）。日志写失败静默忽略，不阻断主操作。
     */
    private function logChange(string $action, string $namespace, string $key, string $type, string $value, ?array $old, string $remark): void
    {
        try {
            $actionText = ['create' => '新增', 'update' => '修改', 'delete' => '删除'][$action] ?? $action;
            $caller = $this->callerFromTrace();
            $context = [
                'action'    => $action,
                'namespace' => $namespace,
                'dict_key'  => $key,
                'value'     => ['value_type' => $type, 'value' => self::summary($value)],
                'remark'    => $remark,
            ];
            if ($old !== null && $action !== 'create') {
                $context['old'] = ['value_type' => (string) $old['value_type'], 'value' => self::summary((string) $old['value'])];
            }
            $this->auditLog()->info(
                '数据字典' . $actionText . ' [' . $namespace . '] ' . $key,
                $context,
                $caller !== '' ? $caller : 'host',
                $caller !== '' ? 'pkg:' . $caller : 'host'
            );
        } catch (\Throwable) {
        }
    }

    /** 值摘要：超 300 字符截断（避免日志文件被大 JSON 撑爆）。 */
    private static function summary(string $raw): string
    {
        return mb_strlen($raw) > 300 ? mb_substr($raw, 0, 300) . '…(截断)' : $raw;
    }

    private function assertKey(string $key): void
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-:]+$/', $key)) {
            throw new \InvalidArgumentException('字典 key 只能包含字母、数字、点、横线、下划线、冒号');
        }
    }
}
