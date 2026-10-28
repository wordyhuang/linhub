<?php

declare(strict_types=1);

namespace linhub\host\services\translation;

use linhub\host\services\support\PlatformStore;

/**
 * 翻译服务实现（TR-1 ~ TR-6）。
 *
 * 存储于 lg_mgt_translation：一行 = 一个 (namespace, dict_key, lang) 译文。
 * 读路径为「按 (namespace, lang) 拉全量 map + 回退合并」，进程内静态缓存 60s；
 * 写操作主动失效对应 namespace 缓存（P3）。
 *
 * 无参构造（自读 env 建 PlatformStore），满足 ServiceInjector 的 app->make()。
 */
final class TranslatorService implements Translator
{
    /** 进程内 map 缓存 TTL（秒），多进程部署下的最长陈旧窗口。 */
    private const CACHE_TTL = 60;

    /** host 保留命名空间：平台公共词条，任何包可读、仅超管可写。 */
    public const HOST_NAMESPACE = 'host';

    private PlatformStore $store;

    /** @var array<string, array{at:int, rows:array<string, array{value:string, is_public:int, owner_pkg:string}>}> */
    private static array $cache = [];

    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    /** 清空进程内缓存（测试与安装/升级/卸载后主动失效使用）。 */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    public function translate(
        ?string $package,
        string $namespace,
        string $key,
        string $lang,
        array $params = [],
        string $fallbackLang = 'zh-CN'
    ): string {
        $namespace = $this->assertNamespace($namespace);
        $key = $this->assertKey($key);
        $lang = $this->assertLang($lang);
        $fallbackLang = $this->assertLang($fallbackLang);
        $caller = $this->resolveCaller($package);

        $text = $this->lookup($caller, $namespace, $key, $lang);
        if ($text === null && $fallbackLang !== '') {
            $text = $this->lookup($caller, $namespace, $key, $fallbackLang);
        }
        return $this->applyParams($text ?? $key, $params);
    }

    public function translateBatch(
        ?string $package,
        string $namespace,
        array $keys,
        string $lang,
        string $fallbackLang = 'zh-CN'
    ): array {
        $namespace = $this->assertNamespace($namespace);
        $lang = $this->assertLang($lang);
        $fallbackLang = $this->assertLang($fallbackLang);
        $caller = $this->resolveCaller($package);

        $out = [];
        foreach ($keys as $key) {
            $key = $this->assertKey((string) $key);
            $text = $this->lookup($caller, $namespace, $key, $lang);
            if ($text === null && $fallbackLang !== '') {
                $text = $this->lookup($caller, $namespace, $key, $fallbackLang);
            }
            $out[$key] = $text ?? $key;
        }
        return $out;
    }

    public function map(?string $package, string $namespace, string $lang): array
    {
        $namespace = $this->assertNamespace($namespace);
        $lang = $this->assertLang($lang);
        $caller = $this->resolveCaller($package);

        $rows = $this->rows($namespace, $lang);
        if ($namespace === self::HOST_NAMESPACE || $namespace === $caller) {
            return array_map(static fn (array $r): string => $r['value'], $rows);
        }
        $out = [];
        foreach ($rows as $key => $row) {
            if ($row['is_public'] === 1) {
                $out[$key] = $row['value'];
            }
        }
        return $out;
    }

    public function put(
        ?string $package,
        string $key,
        string $lang,
        string $value,
        array $meta = [],
        bool $asAdmin = false
    ): array {
        $key = $this->assertKey($key);
        $lang = $this->assertLang($lang);
        $caller = $this->callerFromTrace();
        $namespace = $this->targetNamespace($package, $caller);
        $this->assertWritable($caller, $namespace, $asAdmin);

        $isPublic = !empty($meta['is_public']) ? 1 : 0;
        $owner = $asAdmin ? self::HOST_NAMESPACE : $caller;
        $now = date('c');

        $this->store->ensureTranslationTables();
        $existing = $this->rawRow($namespace, $key, $lang);
        if ($existing === null) {
            $this->store->db()->exec(
                'INSERT INTO ' . PlatformStore::TRANSLATION_TABLE
                . ' (namespace, dict_key, lang, value, is_public, owner_pkg, created_at, updated_at) VALUES ('
                . $this->store->quote($namespace) . ', '
                . $this->store->quote($key) . ', '
                . $this->store->quote($lang) . ', '
                . $this->store->quote($value) . ', '
                . $isPublic . ', '
                . $this->store->quote($owner) . ', '
                . $this->store->quote($now) . ', '
                . $this->store->quote($now) . ')'
            );
        } else {
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::TRANSLATION_TABLE
                . ' SET value=' . $this->store->quote($value)
                . ', is_public=' . $isPublic
                . ', owner_pkg=' . $this->store->quote($owner)
                . ', updated_at=' . $this->store->quote($now)
                . ' WHERE id=' . (int) $existing['id']
            );
        }
        $this->invalidate($namespace);

        $row = $this->rawRow($namespace, $key, $lang) ?? [];
        return [
            'namespace'  => $namespace,
            'key'        => $key,
            'lang'       => $lang,
            'value'      => $value,
            'is_public'  => $isPublic,
            'owner_pkg'  => $owner,
            'created_at' => (string) ($row['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    public function remove(?string $package, string $key, string $lang, bool $asAdmin = false): bool
    {
        $key = $this->assertKey($key);
        $lang = $this->assertLang($lang);
        $caller = $this->callerFromTrace();
        $namespace = $this->targetNamespace($package, $caller);
        $this->assertWritable($caller, $namespace, $asAdmin);

        $this->store->ensureTranslationTables();
        $existing = $this->rawRow($namespace, $key, $lang);
        if ($existing === null) {
            return false;
        }
        $this->store->db()->exec(
            'DELETE FROM ' . PlatformStore::TRANSLATION_TABLE . ' WHERE id=' . (int) $existing['id']
        );
        $this->invalidate($namespace);
        return true;
    }

    public function search(array $filter, int $page = 1, int $size = 20): array
    {
        $this->store->ensureTranslationTables();
        $where = [];
        if (($filter['namespace'] ?? '') !== '') {
            $where[] = 'namespace=' . $this->store->quote($this->assertNamespace((string) $filter['namespace']));
        }
        if (($filter['lang'] ?? '') !== '') {
            $where[] = 'lang=' . $this->store->quote($this->assertLang((string) $filter['lang']));
        }
        if (($filter['keyword'] ?? '') !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filter['keyword']) . '%';
            $where[] = 'dict_key LIKE ' . $this->store->quote($kw);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE . $whereSql
        );
        $total = (int) ($totalRows[0]['c'] ?? 0);
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::TRANSLATION_TABLE . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map(fn (array $r): array => $this->decorate($r), $rows),
        ];
    }

    // —— 管理 GUI 专用（不属于包消费契约） ——

    /** 超管视角全量 map（不受 is_public 限制）。 */
    public function adminMap(string $namespace, string $lang): array
    {
        $namespace = $this->assertNamespace($namespace);
        $lang = $this->assertLang($lang);
        return array_map(
            static fn (array $r): string => $r['value'],
            $this->rows($namespace, $lang)
        );
    }

    /** 汇总：总条目、按命名空间计数、按语言计数（筛选项来源）。 */
    public function stats(): array
    {
        $this->store->ensureTranslationTables();
        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE
        );
        $byNs = $this->store->db()->query(
            'SELECT namespace, COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' GROUP BY namespace ORDER BY c DESC'
        );
        $byLang = $this->store->db()->query(
            'SELECT lang, COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' GROUP BY lang ORDER BY c DESC'
        );
        return [
            'total'      => (int) ($totalRows[0]['c'] ?? 0),
            'namespaces' => array_map(static fn (array $r): array => [
                'namespace' => (string) $r['namespace'],
                'count'     => (int) $r['c'],
            ], $byNs),
            'languages'  => array_map(static fn (array $r): array => [
                'lang'  => (string) $r['lang'],
                'count' => (int) $r['c'],
            ], $byLang),
        ];
    }

    /**
     * 缺失对照：基准语言有、对照语言缺的 key 清单（i18n 刚需）。
     *
     * @return list<array{key:string, value:string}>
     */
    public function missing(string $namespace, string $baseLang, string $compareLang): array
    {
        $base = $this->adminMap($namespace, $baseLang);
        $compared = $this->adminMap($namespace, $compareLang);
        $out = [];
        foreach ($base as $key => $value) {
            if (!array_key_exists($key, $compared)) {
                $out[] = ['key' => (string) $key, 'value' => $value];
            }
        }
        return $out;
    }

    // —— 内部实现 ——

    /** 按可见性查单条译文；命中他包私有词条时抛隔离拒绝。 */
    private function lookup(string $caller, string $namespace, string $key, string $lang): ?string
    {
        if ($lang === '') {
            return null;
        }
        $rows = $this->rows($namespace, $lang);
        if (!isset($rows[$key])) {
            return null;
        }
        $row = $rows[$key];
        if ($namespace === self::HOST_NAMESPACE || $namespace === $caller || $row['is_public'] === 1) {
            return $row['value'];
        }
        throw new TranslatorDenied("无权读取其他功能包的私有词条：{$namespace}/{$key}");
    }

    /** 拉取 (namespace, lang) 全量词条，命中静态缓存则直接返回。 */
    private function rows(string $namespace, string $lang): array
    {
        $cacheKey = $namespace . '|' . $lang;
        $hit = self::$cache[$cacheKey] ?? null;
        if ($hit !== null && (time() - $hit['at']) < self::CACHE_TTL) {
            return $hit['rows'];
        }
        $this->store->ensureTranslationTables();
        $raw = $this->store->db()->query(
            'SELECT dict_key, value, is_public, owner_pkg FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($namespace)
            . ' AND lang=' . $this->store->quote($lang)
        );
        $rows = [];
        foreach ($raw as $r) {
            $rows[(string) $r['dict_key']] = [
                'value'     => (string) $r['value'],
                'is_public' => (int) ($r['is_public'] ?? 0),
                'owner_pkg' => (string) ($r['owner_pkg'] ?? ''),
            ];
        }
        self::$cache[$cacheKey] = ['at' => time(), 'rows' => $rows];
        return $rows;
    }

    private function rawRow(string $namespace, string $key, string $lang): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($namespace)
            . ' AND dict_key=' . $this->store->quote($key)
            . ' AND lang=' . $this->store->quote($lang) . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function decorate(array $row): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'namespace'  => (string) ($row['namespace'] ?? ''),
            'key'        => (string) ($row['dict_key'] ?? ''),
            'lang'       => (string) ($row['lang'] ?? ''),
            'value'      => (string) ($row['value'] ?? ''),
            'is_public'  => (int) ($row['is_public'] ?? 0),
            'owner_pkg'  => (string) ($row['owner_pkg'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** 占位符 {name} 替换；未提供的占位符保留原样。 */
    private function applyParams(string $text, array $params): string
    {
        if ($params === [] || strpos($text, '{') === false) {
            return $text;
        }
        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_.\-]+)\}/',
            static function (array $m) use ($params): string {
                return array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0];
            },
            $text
        );
    }

    /**
     * 写入目标命名空间：显式 $package 优先，否则取调用方包短名。
     */
    private function targetNamespace(?string $package, string $caller): string
    {
        $explicit = $package === null ? '' : trim($package);
        return $explicit !== '' ? $this->assertNamespace($explicit) : $caller;
    }

    /** 隔离守卫：非超管仅可写本包 namespace；host 命名空间仅超管可写。 */
    private function assertWritable(string $caller, string $namespace, bool $asAdmin): void
    {
        if ($asAdmin) {
            return;
        }
        if ($namespace === self::HOST_NAMESPACE) {
            throw new TranslatorDenied('host 命名空间仅超管可写');
        }
        if ($namespace !== $caller) {
            throw new TranslatorDenied("无权写入其他功能包的翻译命名空间：{$namespace}");
        }
    }

    /** 消费侧 caller：显式 $package 优先，否则回溯调用链判定所属功能包。 */
    private function resolveCaller(?string $package): string
    {
        $explicit = $package === null ? '' : trim($package);
        return $explicit !== '' ? $this->assertNamespace($explicit) : $this->callerFromTrace();
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

    /** 写操作后失效该命名空间全部语言缓存。 */
    private function invalidate(string $namespace): void
    {
        $prefix = $namespace . '|';
        foreach (array_keys(self::$cache) as $cacheKey) {
            if (strpos((string) $cacheKey, $prefix) === 0) {
                unset(self::$cache[$cacheKey]);
            }
        }
    }

    private function assertNamespace(string $namespace): string
    {
        $ns = trim($namespace);
        if ($ns === '' || strlen($ns) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $ns)) {
            throw new \InvalidArgumentException('翻译命名空间只能包含字母、数字、点、横线、下划线，且不超过 64 字符');
        }
        return $ns;
    }

    private function assertKey(string $key): string
    {
        $k = trim($key);
        if ($k === '' || strlen($k) > 191 || !preg_match('/^[A-Za-z0-9_.\-:]+$/', $k)) {
            throw new \InvalidArgumentException('翻译 key 只能包含字母、数字、点、横线、下划线、冒号，且不超过 191 字符');
        }
        return $k;
    }

    private function assertLang(string $lang): string
    {
        $v = trim($lang);
        if ($v !== '' && (strlen($v) > 16 || !preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $v))) {
            throw new \InvalidArgumentException('语言须为 BCP-47 简码（如 zh-CN、en-US），且大小写敏感');
        }
        return $v;
    }
}
