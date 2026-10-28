<?php

declare(strict_types=1);

namespace linhub\host\services\recyclebin;

use linhub\host\services\support\PlatformStore;

/**
 * 回收站服务实现（RB-1 ~ RB-8）。
 *
 * 存储于 lg_mgt_recycle_bin：一行 = 一个被删除数据的 JSON 快照条目。
 * payload 为任意结构 JSON（结构解释权归功能包），namespace 做功能包隔离，
 * 回收站数据天然全私有（无公开概念）；host 为保留命名空间。
 *
 * 配额：单条 payload <= 1MB、单包 <= 1000 条（超限抛 RecycleBinQuota）。
 * 保留期：默认永久，put 显式 ttlDays 才有到期；list/stats/put 惰性限量清理到期条目。
 *
 * 无参构造（自读 env 建 PlatformStore），满足 ServiceInjector 的 app->make()。
 */
final class RecycleBinService implements RecycleBin
{
    /** host 保留命名空间：平台自身数据托管，功能包不可读写。 */
    public const HOST_NAMESPACE = 'host';

    /** 单条 payload 上限（json_encode 后字节数）：1MB。 */
    public const MAX_PAYLOAD_BYTES = 1048576;

    /** 单包（命名空间）最大条目数。 */
    public const MAX_ENTRIES_PER_PKG = 1000;

    /** 惰性清理单批上限（两步删除：先查 id 再 IN 删除，方言安全）。 */
    private const LAZY_CLEAN_LIMIT = 50;

    private PlatformStore $store;

    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    public function put(
        ?string $package,
        string $sourceTable,
        string|int $sourcePk,
        mixed $payload,
        array $options = [],
        bool $asAdmin = false
    ): string {
        $sourceTable = $this->assertSourceTable($sourceTable);
        $sourcePk = $this->assertSourcePk($sourcePk);
        $caller = $this->callerFromTrace();
        $namespace = $this->targetNamespace($package, $caller);
        $this->assertWritable($caller, $namespace, $asAdmin);

        $json = json_encode($payload);
        if ($json === false) {
            throw new \InvalidArgumentException('payload 无法 JSON 编码（须为 UTF-8 可序列化数据）：' . json_last_error_msg());
        }
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new RecycleBinQuota('payload 超过单条上限 ' . self::MAX_PAYLOAD_BYTES . ' 字节（1MB）');
        }

        $this->store->ensureRecycleBinTables();
        if ($this->countNamespace($namespace) >= self::MAX_ENTRIES_PER_PKG) {
            throw new RecycleBinQuota("命名空间 {$namespace} 的回收站条目已达上限 " . self::MAX_ENTRIES_PER_PKG . ' 条');
        }

        $itemKey = '';
        if (isset($options['itemKey']) && (string) $options['itemKey'] !== '') {
            $itemKey = $this->assertItemKey((string) $options['itemKey']);
        }
        $title = '';
        if (isset($options['title']) && (string) $options['title'] !== '') {
            $title = $this->assertTitle((string) $options['title']);
        }
        $metaJson = '';
        if (isset($options['meta']) && is_array($options['meta']) && $options['meta'] !== []) {
            $encoded = json_encode($options['meta']);
            if ($encoded !== false) {
                $metaJson = $encoded;
            }
        }
        $expiresAt = '';
        if (array_key_exists('ttlDays', $options) && $options['ttlDays'] !== null) {
            $ttl = $options['ttlDays'];
            if (!is_int($ttl) && !is_numeric($ttl)) {
                throw new \InvalidArgumentException('ttlDays 须为正整数天数');
            }
            $ttl = (int) $ttl;
            if ($ttl > 0) {
                $expiresAt = date('c', time() + $ttl * 86400);
            }
        }

        $token = bin2hex(random_bytes(16));
        $now = date('c');
        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::RECYCLE_BIN_TABLE
            . ' (namespace, token, source_table, source_pk, item_key, title, payload, payload_size, meta, orphaned_at, deleted_at, expires_at, created_at, updated_at) VALUES ('
            . $this->store->quote($namespace) . ', '
            . $this->store->quote($token) . ', '
            . $this->store->quote($sourceTable) . ', '
            . $this->store->quote($sourcePk) . ', '
            . $this->store->quote($itemKey) . ', '
            . $this->store->quote($title) . ', '
            . $this->store->quote($json) . ', '
            . strlen($json) . ', '
            . $this->store->quote($metaJson) . ", "
            . "'', "
            . $this->store->quote($now) . ', '
            . $this->store->quote($expiresAt) . ', '
            . $this->store->quote($now) . ', '
            . $this->store->quote($now) . ')'
        );

        $this->lazyClean();
        return $token;
    }

    public function get(?string $package, string $token, bool $asAdmin = false): ?array
    {
        $caller = $this->callerFromTrace();
        if ($package !== null && $this->assertNamespace($package) !== $caller) {
            $this->assertWritable($caller, $this->assertNamespace($package), $asAdmin);
        }
        $row = $this->rowByToken($token);
        if ($row === null) {
            return null;
        }
        $this->assertWritable($caller, (string) $row['namespace'], $asAdmin);
        return $this->decorate($row);
    }

    public function list(?string $package, array $filter, int $page = 1, int $size = 20,
        bool $asAdmin = false): array {
        $caller = $this->callerFromTrace();
        $nsFilter = trim((string) ($filter['namespace'] ?? ''));
        if (!$asAdmin) {
            if ($package !== null && $this->assertNamespace($package) !== $caller) {
                throw new RecycleBinDenied("无权读取其他功能包的回收站：{$package}");
            }
            if ($nsFilter !== '' && $nsFilter !== $caller) {
                throw new RecycleBinDenied("无权读取其他功能包的回收站：{$nsFilter}");
            }
            $nsFilter = $caller;
        } elseif ($nsFilter !== '') {
            $nsFilter = $this->assertNamespace($nsFilter);
        }

        $this->store->ensureRecycleBinTables();
        $where = [];
        if ($nsFilter !== '') {
            $where[] = 'namespace=' . $this->store->quote($nsFilter);
        }
        if (($filter['source_table'] ?? '') !== '') {
            $where[] = 'source_table=' . $this->store->quote(trim((string) $filter['source_table']));
        }
        if (($filter['item_key'] ?? '') !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filter['item_key'])) . '%';
            $where[] = 'item_key LIKE ' . $this->store->quote($kw);
        }
        if (($filter['keyword'] ?? '') !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filter['keyword'])) . '%';
            $where[] = '(title LIKE ' . $this->store->quote($kw)
                . ' OR item_key LIKE ' . $this->store->quote($kw)
                . ' OR source_pk LIKE ' . $this->store->quote($kw) . ')';
        }
        $orphaned = (string) ($filter['orphaned'] ?? '');
        if ($orphaned === 'yes') {
            $where[] = "orphaned_at != ''";
        } elseif ($orphaned === 'no') {
            $where[] = "orphaned_at = ''";
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECYCLE_BIN_TABLE . $whereSql
        );
        $total = (int) ($totalRows[0]['c'] ?? 0);
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::RECYCLE_BIN_TABLE . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        $this->lazyClean();
        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map(fn (array $r): array => $this->decorateBrief($r), $rows),
        ];
    }

    public function restore(?string $package, string $token, bool $asAdmin = false): array
    {
        $caller = $this->callerFromTrace();
        if ($package !== null && $this->assertNamespace($package) !== $caller) {
            $this->assertWritable($caller, $this->assertNamespace($package), $asAdmin);
        }
        $row = $this->rowByToken($token);
        if ($row === null) {
            throw new \InvalidArgumentException('回收站条目不存在：' . $token);
        }
        $this->assertWritable($caller, (string) $row['namespace'], $asAdmin);
        return $this->decorate($row);
    }

    public function purge(?string $package, string $token, bool $asAdmin = false): bool
    {
        $caller = $this->callerFromTrace();
        if ($package !== null && $this->assertNamespace($package) !== $caller) {
            $this->assertWritable($caller, $this->assertNamespace($package), $asAdmin);
        }
        $row = $this->rowByToken($token);
        if ($row === null) {
            return false;
        }
        $this->assertWritable($caller, (string) $row['namespace'], $asAdmin);
        $this->store->db()->exec(
            'DELETE FROM ' . PlatformStore::RECYCLE_BIN_TABLE . ' WHERE id=' . (int) $row['id']
        );
        return true;
    }

    public function purgeBatch(?string $package, array $tokens, bool $asAdmin = false): int
    {
        // 整批预检：任一条目无权操作时拒绝，不做部分删除
        $caller = $this->callerFromTrace();
        if ($package !== null && $this->assertNamespace($package) !== $caller) {
            $this->assertWritable($caller, $this->assertNamespace($package), $asAdmin);
        }
        $rows = [];
        foreach ($tokens as $token) {
            $token = $this->assertToken((string) $token);
            $row = $this->rowByToken($token);
            if ($row === null) {
                continue;
            }
            $this->assertWritable($caller, (string) $row['namespace'], $asAdmin);
            $rows[] = $row;
        }
        $count = 0;
        foreach ($rows as $row) {
            $this->store->db()->exec(
                'DELETE FROM ' . PlatformStore::RECYCLE_BIN_TABLE . ' WHERE id=' . (int) $row['id']
            );
            $count++;
        }
        return $count;
    }

    public function stats(): array
    {
        $this->store->ensureRecycleBinTables();
        $table = PlatformStore::RECYCLE_BIN_TABLE;
        $totalRows = $this->store->db()->query("SELECT COUNT(*) AS c FROM {$table}");
        $orphanRows = $this->store->db()->query("SELECT COUNT(*) AS c FROM {$table} WHERE orphaned_at != ''");
        $horizon = date('c', time() + 7 * 86400);
        $expiringRows = $this->store->db()->query(
            "SELECT COUNT(*) AS c FROM {$table} WHERE expires_at != '' AND expires_at <= " . $this->store->quote($horizon)
        );
        $byNs = $this->store->db()->query(
            "SELECT namespace, COUNT(*) AS c, SUM(CASE WHEN orphaned_at != '' THEN 1 ELSE 0 END) AS o"
            . " FROM {$table} GROUP BY namespace ORDER BY c DESC"
        );
        $this->lazyClean();
        return [
            'total'    => (int) ($totalRows[0]['c'] ?? 0),
            'orphaned' => (int) ($orphanRows[0]['c'] ?? 0),
            'expiring' => (int) ($expiringRows[0]['c'] ?? 0),
            'namespaces' => array_map(static fn (array $r): array => [
                'namespace' => (string) $r['namespace'],
                'count'     => (int) $r['c'],
                'orphaned'  => (int) ($r['o'] ?? 0),
            ], $byNs),
        ];
    }

    public function purgeExpired(?string $package = null, bool $asAdmin = false): int
    {
        $caller = $this->callerFromTrace();
        $scope = '';
        if ($package !== null && trim($package) !== '') {
            $scope = $this->assertNamespace($package);
            $this->assertWritable($caller, $scope, $asAdmin);
        } elseif (!$asAdmin) {
            // 非 admin 且未显式指定命名空间：只允许清理自己命名空间的到期条目
            $scope = $caller;
        }
        $this->store->ensureRecycleBinTables();
        return $this->deleteExpired($scope === '' ? null : $scope);
    }

    // —— 生命周期接线（实现类特有，不进 RecycleBin 契约） ——

    /** 卸载期：该包命名空间全部正常条目打孤儿态（数据保留）。返回打标条数。 */
    public function orphanPackage(string $short): int
    {
        $short = $this->assertNamespace($short);
        $this->store->ensureRecycleBinTables();
        $db = $this->store->db();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM " . PlatformStore::RECYCLE_BIN_TABLE
            . ' WHERE namespace=' . $this->store->quote($short) . " AND orphaned_at = ''"
        );
        $n = (int) ($rows[0]['c'] ?? 0);
        if ($n > 0) {
            $db->exec(
                'UPDATE ' . PlatformStore::RECYCLE_BIN_TABLE
                . ' SET orphaned_at=' . $this->store->quote(date('c'))
                . ' WHERE namespace=' . $this->store->quote($short) . " AND orphaned_at = ''"
            );
        }
        return $n;
    }

    /** 重装/升级期：接管该包命名空间的孤儿条目（清孤儿标记）。返回接管条数。 */
    public function adoptPackage(string $short): int
    {
        $short = $this->assertNamespace($short);
        $this->store->ensureRecycleBinTables();
        $db = $this->store->db();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM " . PlatformStore::RECYCLE_BIN_TABLE
            . ' WHERE namespace=' . $this->store->quote($short) . " AND orphaned_at != ''"
        );
        $n = (int) ($rows[0]['c'] ?? 0);
        if ($n > 0) {
            $db->exec(
                'UPDATE ' . PlatformStore::RECYCLE_BIN_TABLE
                . " SET orphaned_at=''"
                . ' WHERE namespace=' . $this->store->quote($short) . " AND orphaned_at != ''"
            );
        }
        return $n;
    }

    /** admin 特权：调整条目保留期（$days=0 表示永久，>0 表示 N 天后到期）。返回是否命中条目。 */
    public function extendTtl(string $token, int $days, bool $asAdmin = false): bool
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('保留天数不能为负数');
        }
        $caller = $this->callerFromTrace();
        $row = $this->rowByToken($token);
        if ($row === null) {
            return false;
        }
        $this->assertWritable($caller, (string) $row['namespace'], $asAdmin);
        $expiresAt = $days === 0 ? '' : date('c', time() + $days * 86400);
        $this->store->db()->exec(
            'UPDATE ' . PlatformStore::RECYCLE_BIN_TABLE
            . ' SET expires_at=' . $this->store->quote($expiresAt)
            . ', updated_at=' . $this->store->quote(date('c'))
            . ' WHERE id=' . (int) $row['id']
        );
        return true;
    }

    // —— 内部实现 ——

    private function rowByToken(string $token): ?array
    {
        $token = $this->assertToken($token);
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::RECYCLE_BIN_TABLE
            . ' WHERE token=' . $this->store->quote($token) . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function countNamespace(string $namespace): int
    {
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECYCLE_BIN_TABLE
            . ' WHERE namespace=' . $this->store->quote($namespace)
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    /** 删除到期条目；$namespace=null 清全部命名空间。返回删除条数。 */
    private function deleteExpired(?string $namespace): int
    {
        $where = " WHERE expires_at != '' AND expires_at <= " . $this->store->quote(date('c'));
        if ($namespace !== null) {
            $where .= ' AND namespace=' . $this->store->quote($namespace);
        }
        $db = $this->store->db();
        $rows = $db->query('SELECT id FROM ' . PlatformStore::RECYCLE_BIN_TABLE . $where);
        if ($rows === []) {
            return 0;
        }
        $ids = implode(',', array_map(static fn ($r): int => (int) $r['id'], $rows));
        $db->exec('DELETE FROM ' . PlatformStore::RECYCLE_BIN_TABLE . " WHERE id IN ({$ids})");
        return count($rows);
    }

    /** 惰性清理：list/stats/put 时顺手限量清一批到期条目（两步删除，方言安全）。 */
    private function lazyClean(): void
    {
        try {
            $rows = $this->store->db()->query(
                'SELECT id FROM ' . PlatformStore::RECYCLE_BIN_TABLE
                . " WHERE expires_at != '' AND expires_at <= " . $this->store->quote(date('c'))
                . ' LIMIT ' . self::LAZY_CLEAN_LIMIT
            );
            if ($rows === []) {
                return;
            }
            $ids = implode(',', array_map(static fn ($r): int => (int) $r['id'], $rows));
            $this->store->db()->exec(
                'DELETE FROM ' . PlatformStore::RECYCLE_BIN_TABLE . " WHERE id IN ({$ids})"
            );
        } catch (\Throwable) {
            // 惰性清理失败不阻断主流程（下次调用重试）
        }
    }

    /** 完整装饰（get/restore）：payload 与 meta 已解码。 */
    private function decorate(array $row): array
    {
        $payloadRaw = (string) ($row['payload'] ?? '');
        $payload = json_decode($payloadRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $payload = $payloadRaw; // 历史脏数据防御：解码失败原样返回字符串
        }
        $metaRaw = (string) ($row['meta'] ?? '');
        $meta = $metaRaw === '' ? [] : (json_decode($metaRaw, true) ?: []);
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'token'        => (string) ($row['token'] ?? ''),
            'namespace'    => (string) ($row['namespace'] ?? ''),
            'source_table' => (string) ($row['source_table'] ?? ''),
            'source_pk'    => (string) ($row['source_pk'] ?? ''),
            'item_key'     => (string) ($row['item_key'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'payload'      => $payload,
            'payload_size' => (int) ($row['payload_size'] ?? 0),
            'meta'         => $meta,
            'orphaned_at'  => (string) ($row['orphaned_at'] ?? ''),
            'deleted_at'   => (string) ($row['deleted_at'] ?? ''),
            'expires_at'   => (string) ($row['expires_at'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'updated_at'   => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** 轻量装饰（list）：不含 payload/meta 大字段。 */
    private function decorateBrief(array $row): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'token'        => (string) ($row['token'] ?? ''),
            'namespace'    => (string) ($row['namespace'] ?? ''),
            'source_table' => (string) ($row['source_table'] ?? ''),
            'source_pk'    => (string) ($row['source_pk'] ?? ''),
            'item_key'     => (string) ($row['item_key'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'payload_size' => (int) ($row['payload_size'] ?? 0),
            'orphaned_at'  => (string) ($row['orphaned_at'] ?? ''),
            'deleted_at'   => (string) ($row['deleted_at'] ?? ''),
            'expires_at'   => (string) ($row['expires_at'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'updated_at'   => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** 写入目标命名空间：显式 $package 优先，否则取调用方包短名。 */
    private function targetNamespace(?string $package, string $caller): string
    {
        $explicit = $package === null ? '' : trim($package);
        return $explicit !== '' ? $this->assertNamespace($explicit) : $caller;
    }

    /** 隔离守卫：回收站数据全私有——namespace 必须等于 caller（asAdmin 例外）。 */
    private function assertWritable(string $caller, string $namespace, bool $asAdmin): void
    {
        if ($asAdmin || $namespace === $caller) {
            return;
        }
        throw new RecycleBinDenied("无权操作其他功能包的回收站命名空间：{$namespace}");
    }

    /**
     * 回溯调用栈判定 caller 所属功能包：扫描 frame file 是否落在插件加载根下。
     * 命中即取路径首段短名；无法判定时返回 host。
     */
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
        return self::HOST_NAMESPACE;
    }

    private function assertNamespace(string $namespace): string
    {
        $ns = trim($namespace);
        if ($ns === '' || strlen($ns) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $ns)) {
            throw new \InvalidArgumentException('回收站命名空间只能包含字母、数字、点、横线、下划线，且不超过 64 字符');
        }
        return $ns;
    }

    private function assertToken(string $token): string
    {
        $t = trim($token);
        if ($t === '' || strlen($t) > 64 || !preg_match('/^[A-Za-z0-9]+$/', $t)) {
            throw new \InvalidArgumentException('回收站条目标识不合法');
        }
        return $t;
    }

    private function assertSourceTable(string $sourceTable): string
    {
        $v = trim($sourceTable);
        if ($v === '' || strlen($v) > 191) {
            throw new \InvalidArgumentException('source_table 须为非空且不超过 191 字符的来源表标识');
        }
        return $v;
    }

    private function assertSourcePk(string|int $sourcePk): string
    {
        $v = trim((string) $sourcePk);
        if ($v === '' || strlen($v) > 191) {
            throw new \InvalidArgumentException('source_pk 须为非空且不超过 191 字符的来源主键值');
        }
        return $v;
    }

    private function assertItemKey(string $itemKey): string
    {
        $v = trim($itemKey);
        if ($v === '' || strlen($v) > 191) {
            throw new \InvalidArgumentException('itemKey 须为非空且不超过 191 字符');
        }
        return $v;
    }

    private function assertTitle(string $title): string
    {
        $v = trim($title);
        if ($v === '' || strlen($v) > 255) {
            throw new \InvalidArgumentException('title 须为非空且不超过 255 字符');
        }
        return $v;
    }
}
