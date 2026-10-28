<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

use linhub\capability\auth\Identity;
use linhub\host\services\support\PlatformStore;
use think\facade\Env;

/**
 * 附件托管服务实现（AT-1 ~ AT-12）。
 *
 * 无参友好构造（ServiceInjector 以 $app->make() 装配）：自读 env、自建 PlatformStore，
 * 元数据落 lg_mgt_attachment，物理存储经 StorageFactory 装配（local/qiniu）。
 * 可托管参数（默认公开性/默认有效期/缺图兜底开关）与默认图 Token 存数据字典表
 * （namespace='platform'），管理页改设置即时生效，默认仍由 .env 兜底。
 */
final class AttachmentService implements Attachment
{
    /** 平台自身在数据字典中的命名空间（默认图 Token、托管参数）。 */
    public const SETTING_NS = 'platform';
    public const SETTING_KEY_FALLBACK_TOKEN = 'attach.fallback_token';
    public const SETTING_KEY_DEFAULT_PUBLIC = 'attach.default_public';
    public const SETTING_KEY_DEFAULT_EXPIRE = 'attach.default_expire_days';
    public const SETTING_KEY_FALLBACK_ENABLED = 'attach.fallback_enabled';

    private PlatformStore $store;
    private ?StorageInterface $driver = null;

    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    public function store(string $ownerPackage, string $content, string $originName, array $meta = []): array
    {
        if ($content === '') {
            throw new \InvalidArgumentException('附件内容为空');
        }
        $this->store->ensureAttachmentTables();

        $size = strlen($content);
        $this->assertSizeAllowed($size);

        $ext  = $this->extOf($originName);
        $this->assertTypeAllowed($ext);

        $mime = (string) ($meta['mime'] ?? '');
        if ($mime === '') {
            $mime = $this->mimeOf($ext);
        }
        $isImage = $this->isImageMime($mime) || $this->isImageExt($ext);

        $isPublic = array_key_exists('is_public', $meta)
            ? (bool) $meta['is_public']
            : $this->defaultPublic();
        $expireDays = array_key_exists('expire_days', $meta)
            ? (int) $meta['expire_days']
            : $this->defaultExpireDays();

        $token = $this->newToken();
        $key   = $this->buildKey($token, $ext);
        $this->driver()->put($key, $content);

        $now = date('c');
        $expireAt = $expireDays > 0 ? date('c', time() + $expireDays * 86400) : '';

        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::ATTACHMENT_TABLE
            . ' (token, owner_pkg, uploader_id, uploader_name, origin_name, storage_path, driver, '
            . 'mime, ext, size, is_public, is_image, status, expire_at, created_at, updated_at) VALUES ('
            . $this->store->quote($token) . ', '
            . $this->store->quote($ownerPackage) . ', '
            . $this->store->quote((string) ($meta['uploader_id'] ?? '')) . ', '
            . $this->store->quote((string) ($meta['uploader_name'] ?? '')) . ', '
            . $this->store->quote($originName) . ', '
            . $this->store->quote($key) . ', '
            . $this->store->quote($this->driver()->name()) . ', '
            . $this->store->quote($mime) . ', '
            . $this->store->quote($ext) . ', '
            . $size . ', '
            . ($isPublic ? 1 : 0) . ', '
            . ($isImage ? 1 : 0) . ', '
            . $this->store->quote('active') . ', '
            . $this->store->quote($expireAt) . ', '
            . $this->store->quote($now) . ', '
            . $this->store->quote($now) . ')'
        );

        return $this->findByToken($token) ?? [];
    }

    public function fetch(string $token, ?Identity $viewer = null): array
    {
        $this->store->ensureAttachmentTables();
        $row = $this->findByToken($token);

        // 无元数据：无法判定图片类，返回 404（下载控制器可用 ?image=1 显式走兜底）。
        if ($row === null) {
            throw new AttachmentNotFound('附件不存在');
        }
        if ((int) $row['is_public'] !== 1 && !$this->canAccess($row, $viewer)) {
            throw new AttachmentDenied('无权访问该私有附件');
        }

        $missing = $row['status'] !== 'active'
            || ($row['expire_at'] !== '' && strcmp($row['expire_at'], date('c')) < 0);

        if (!$missing) {
            try {
                $content = $this->driverFor($row['driver'])->get($row['storage_path']);
                return [
                    'content'    => $content,
                    'mime'       => $row['mime'] ?: $this->mimeOf($row['ext']),
                    'name'       => $row['origin_name'],
                    'attachment' => $row,
                    'fallback'   => false,
                ];
            } catch (\Throwable $e) {
                $missing = true; // 物理对象丢失，落到下面的兜底/404
            }
        }

        if ((int) $row['is_image'] === 1) {
            $fb = $this->fallbackImage();
            return [
                'content'    => $fb['content'],
                'mime'       => $fb['mime'],
                'name'       => 'fallback' . ($fb['ext'] ?? '.svg'),
                'attachment' => $row,
                'fallback'   => true,
            ];
        }
        throw new AttachmentNotFound('附件不存在或已过期');
    }

    public function delete(string $token, ?Identity $viewer = null, bool $asAdmin = false): void
    {
        $this->store->ensureAttachmentTables();
        $row = $this->findByToken($token);
        if ($row === null) {
            return;
        }
        if (!$asAdmin && !$this->isOwner($row, $viewer)) {
            throw new AttachmentDenied('仅管理员或上传者本人可删除该附件');
        }
        try {
            $this->driverFor($row['driver'])->delete($row['storage_path']);
        } catch (\Throwable $e) {
            // 物理删除失败不阻断元数据失效；清理任务后续兜底。
        }
        $now = date('c');
        $this->store->db()->exec(
            'UPDATE ' . PlatformStore::ATTACHMENT_TABLE . ' SET status=' . $this->store->quote('deleted')
            . ', updated_at=' . $this->store->quote($now)
            . ' WHERE token=' . $this->store->quote($token)
        );
    }

    public function findByToken(string $token): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::ATTACHMENT_TABLE
            . ' WHERE token=' . $this->store->quote($token) . ' LIMIT 1'
        );
        $row = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    public function url(string $token): string
    {
        return '/attachment/download?token=' . rawurlencode($token);
    }

    public function paginate(array $filter = [], int $page = 1, int $size = 20): array
    {
        $this->store->ensureAttachmentTables();
        $where = [];
        if (($filter['status'] ?? '') !== '') {
            $where[] = 'status=' . $this->store->quote((string) $filter['status']);
        } else {
            $where[] = "status='active'";
        }
        if (($filter['owner_pkg'] ?? '') !== '') {
            $where[] = 'owner_pkg=' . $this->store->quote((string) $filter['owner_pkg']);
        }
        if (($filter['uploader_id'] ?? '') !== '') {
            $where[] = 'uploader_id=' . $this->store->quote((string) $filter['uploader_id']);
        }
        if (($filter['is_public'] ?? '') !== '') {
            $where[] = 'is_public=' . $this->store->int($filter['is_public'] ? 1 : 0);
        }
        if (($filter['keyword'] ?? '') !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filter['keyword']) . '%';
            $where[] = 'origin_name LIKE ' . $this->store->quote($kw);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $totalRows = $this->store->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::ATTACHMENT_TABLE . $whereSql);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $offset = ($page - 1) * $size;
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::ATTACHMENT_TABLE . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset
        );

        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $rows];
    }

    public function stats(): array
    {
        $this->store->ensureAttachmentTables();
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(size),0) AS bytes, '
            . 'SUM(CASE WHEN is_public=1 THEN 1 ELSE 0 END) AS public_count, '
            . "SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_count "
            . 'FROM ' . PlatformStore::ATTACHMENT_TABLE
        );
        $r = $rows[0] ?? [];
        return [
            'total'        => (int) ($r['total'] ?? 0),
            'active'       => (int) ($r['active_count'] ?? 0),
            'public'       => (int) ($r['public_count'] ?? 0),
            'bytes'        => (int) ($r['bytes'] ?? 0),
            'driver'       => $this->driver()->name(),
        ];
    }

    /**
     * 近 N 天新增附件趋势（按 created_at 按天聚合，含已删，供总览看板图表）。
     *
     * @return list<array{date:string,total:int,bytes:int}> 无新增的日期补 0
     */
    public function trend(int $days = 7): array
    {
        $this->store->ensureAttachmentTables();
        $days    = max(1, min(90, $days));
        $fromDay = date('Y-m-d', time() - ($days - 1) * 86400);
        // created_at 以 date('c')（含时区偏移）落库，比较值也用同口径 ISO8601
        $fromIso = date('c', (int) strtotime($fromDay . ' 00:00:00'));
        $rows = $this->store->db()->query(
            'SELECT LEFT(created_at, 10) AS d, COUNT(*) AS c, COALESCE(SUM(size),0) AS bytes '
            . 'FROM ' . PlatformStore::ATTACHMENT_TABLE
            . ' WHERE created_at >= ' . $this->store->quote($fromIso)
            . ' GROUP BY LEFT(created_at, 10)'
        );

        $bucket = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $bucket[date('Y-m-d', time() - $i * 86400)] = ['total' => 0, 'bytes' => 0];
        }
        foreach ((array) $rows as $r) {
            $day = (string) ($r['d'] ?? '');
            if (isset($bucket[$day])) {
                $bucket[$day]['total'] += (int) ($r['c'] ?? 0);
                $bucket[$day]['bytes'] += (int) ($r['bytes'] ?? 0);
            }
        }
        $out = [];
        foreach ($bucket as $date => $v) {
            $out[] = ['date' => $date, 'total' => $v['total'], 'bytes' => $v['bytes']];
        }
        return $out;
    }

    public function configState(): array
    {
        $driver = strtolower((string) Env::get('ATTACH_DRIVER', 'local'));
        $qiniu = new QiniuDriver();
        return [
            'driver'               => $driver,
            'local_root'           => LocalDriver::defaultRoot(),
            'max_size_raw'         => (string) Env::get('ATTACH_MAX_SIZE', '10M'),
            'max_size_bytes'       => $this->parseSize((string) Env::get('ATTACH_MAX_SIZE', '10M')),
            'allow_types'          => $this->allowTypes(),
            'default_public'       => $this->defaultPublic(),
            'default_expire_days'  => $this->defaultExpireDays(),
            'fallback_enabled'     => $this->fallbackEnabled(),
            'fallback_configured'  => $this->setting(self::SETTING_KEY_FALLBACK_TOKEN) !== '',
            'clean_enabled'        => ((string) Env::get('ATTACH_CLEAN_ENABLED', '0')) === '1',
            'clean_cron'           => (string) Env::get('ATTACH_CLEAN_CRON', ''),
            'qiniu' => [
                'configured'    => $qiniu->configured(),
                'sdk_available' => QiniuDriver::sdkAvailable(),
                'bucket'        => (string) Env::get('ATTACH_QINIU_BUCKET', ''),
                'domain_set'    => (string) Env::get('ATTACH_QINIU_DOMAIN', '') !== '',
            ],
        ];
    }

    public function testDriver(string $driver = ''): array
    {
        try {
            $instance = $driver !== '' ? StorageFactory::make($driver) : $this->driver();
            return ['driver' => $instance->name()] + $instance->ping();
        } catch (\Throwable $e) {
            return ['driver' => $driver ?: 'current', 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function setFallbackImage(string $content, string $originName, string $operatorId = ''): array
    {
        $meta = $this->store(
            self::SETTING_NS,
            $content,
            $originName,
            ['is_public' => true, 'uploader_id' => $operatorId, 'uploader_name' => '平台默认图'],
        );
        $this->putSetting(self::SETTING_KEY_FALLBACK_TOKEN, (string) ($meta['token'] ?? ''), 'string');
        return $meta;
    }

    public function resetFallbackImage(): void
    {
        $this->putSetting(self::SETTING_KEY_FALLBACK_TOKEN, '', 'string');
    }

    /** 当前已托管默认图 Token；未配置返回空串（AT-9 管理页预览用）。 */
    public function fallbackToken(): string
    {
        return $this->setting(self::SETTING_KEY_FALLBACK_TOKEN);
    }

    public function fallbackImage(): array
    {
        $token = $this->setting(self::SETTING_KEY_FALLBACK_TOKEN);
        if ($token !== '') {
            $row = $this->findByToken($token);
            if ($row !== null && $row['status'] === 'active') {
                try {
                    return [
                        'content'    => $this->driverFor($row['driver'])->get($row['storage_path']),
                        'mime'       => $row['mime'] ?: 'image/png',
                        'ext'        => $row['ext'] ?: '.png',
                        'configured' => true,
                    ];
                } catch (\Throwable $e) {
                    // 落到内置默认图
                }
            }
        }
        return [
            'content'    => $this->builtinFallbackSvg(),
            'mime'       => 'image/svg+xml',
            'ext'        => '.svg',
            'configured' => false,
        ];
    }

    public function saveSettings(array $settings): void
    {
        if (array_key_exists('default_public', $settings)) {
            $this->putSetting(self::SETTING_KEY_DEFAULT_PUBLIC, !empty($settings['default_public']) ? '1' : '0', 'string');
        }
        if (array_key_exists('default_expire_days', $settings)) {
            $this->putSetting(self::SETTING_KEY_DEFAULT_EXPIRE, (string) max(0, (int) $settings['default_expire_days']), 'int');
        }
        if (array_key_exists('fallback_enabled', $settings)) {
            $this->putSetting(self::SETTING_KEY_FALLBACK_ENABLED, !empty($settings['fallback_enabled']) ? '1' : '0', 'string');
        }
    }

    public function clean(string $triggerType = 'manual', string $operatorId = ''): array
    {
        $this->store->ensureAttachmentTables();
        $now = date('c');
        $scanned = 0;
        $deleted = 0;
        $bytesFreed = 0;
        $detail = [];

        // 1) 过期附件：物理删除 + 状态置 expired（保留元数据，图片请求继续走兜底）。
        $expired = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::ATTACHMENT_TABLE
            . " WHERE status='active' AND expire_at<>'' AND expire_at<" . $this->store->quote($now)
        );
        foreach ($expired as $row) {
            $scanned++;
            try {
                $this->driverFor($row['driver'])->delete($row['storage_path']);
            } catch (\Throwable $e) {
                $detail[] = '过期对象删除失败：' . $row['token'] . ' ' . $e->getMessage();
            }
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::ATTACHMENT_TABLE . " SET status='expired', updated_at="
                . $this->store->quote($now) . ' WHERE id=' . (int) $row['id']
            );
            $deleted++;
            $bytesFreed += (int) $row['size'];
        }

        // 2) 孤儿对象：仅对本地驱动，物理文件无 active 元数据则清除。
        $orphanFiles = 0;
        $driver = $this->driver();
        if ($driver instanceof LocalDriver) {
            $activePaths = [];
            foreach ($this->store->db()->query(
                'SELECT storage_path FROM ' . PlatformStore::ATTACHMENT_TABLE . " WHERE driver='local'"
            ) as $r) {
                $activePaths[(string) $r['storage_path']] = true;
            }
            foreach ($driver->listKeys() as $key) {
                $scanned++;
                if (!isset($activePaths[$key])) {
                    @unlink($driver->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key));
                    $orphanFiles++;
                    $deleted++;
                }
            }
        }

        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::ATTACH_CLEAN_TABLE
            . ' (trigger_type, driver, scanned, deleted, detail, operator_id, created_at) VALUES ('
            . $this->store->quote($triggerType) . ', '
            . $this->store->quote($driver->name()) . ', '
            . $scanned . ', ' . $deleted . ', '
            . $this->store->quote(implode("\n", $detail) ?: '正常') . ', '
            . $this->store->quote($operatorId) . ', '
            . $this->store->quote($now) . ')'
        );

        return [
            'scanned'     => $scanned,
            'deleted'     => $deleted,
            'expired'     => count($expired),
            'orphan_files' => $orphanFiles,
            'bytes_freed' => $bytesFreed,
            'detail'      => $detail,
            'created_at'  => $now,
        ];
    }

    public function cleanRecords(int $page = 1, int $size = 20): array
    {
        $this->store->ensureAttachmentTables();
        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $totalRows = $this->store->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::ATTACH_CLEAN_TABLE);
        $total = (int) ($totalRows[0]['c'] ?? 0);
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::ATTACH_CLEAN_TABLE
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $rows];
    }

    // ===== 内部方法 =====

    private function driver(): StorageInterface
    {
        return $this->driver ??= StorageFactory::make();
    }

    private function driverFor(string $driver): StorageInterface
    {
        return strtolower($driver) === $this->driver()->name() ? $this->driver() : StorageFactory::make($driver);
    }

    private function canAccess(array $row, ?Identity $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }
        if ($viewer->hasTag((string) Env::get('UAIS_ADMIN_TAG', 'LinHub SuperAdministrator'))) {
            return true;
        }
        return $this->isOwner($row, $viewer);
    }

    private function isOwner(array $row, ?Identity $viewer): bool
    {
        return $viewer !== null
            && (string) $row['uploader_id'] !== ''
            && (string) $row['uploader_id'] === $viewer->id;
    }

    private function newToken(): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), 0, 24);
    }

    private function buildKey(string $token, string $ext): string
    {
        return date('Ym') . '/' . substr($token, 0, 2) . '/' . $token . $ext;
    }

    private function extOf(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false) {
            return '';
        }
        return strtolower(substr($name, $pos));
    }

    private function assertSizeAllowed(int $size): void
    {
        $max = $this->parseSize((string) Env::get('ATTACH_MAX_SIZE', '10M'));
        if ($max > 0 && $size > $max) {
            throw new \InvalidArgumentException('附件大小超过上限：' . (string) Env::get('ATTACH_MAX_SIZE', '10M'));
        }
    }

    private function assertTypeAllowed(string $ext): void
    {
        $allowed = $this->allowTypes();
        if ($allowed === []) {
            return; // 白名单为空表示不限制
        }
        $needle = ltrim($ext, '.');
        if (!in_array($needle, $allowed, true)) {
            throw new \InvalidArgumentException('不允许的附件类型：' . ($needle ?: '未知'));
        }
    }

    private function allowTypes(): array
    {
        $raw = (string) Env::get('ATTACH_ALLOW_TYPES', '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($v): string => strtolower(trim(trim($v), '.')),
            explode(',', $raw),
        )));
    }

    private function parseSize(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^(\d+)\s*([kmgKMG]?)$/', $raw, $m)) {
            return 0;
        }
        $n = (int) $m[1];
        return match (strtoupper($m[2])) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    private function defaultPublic(): bool
    {
        $saved = $this->setting(self::SETTING_KEY_DEFAULT_PUBLIC);
        if ($saved !== '') {
            return $saved === '1';
        }
        return strtolower((string) Env::get('ATTACH_DEFAULT_VISIBILITY', 'private')) === 'public';
    }

    private function defaultExpireDays(): int
    {
        $saved = $this->setting(self::SETTING_KEY_DEFAULT_EXPIRE);
        if ($saved !== '') {
            return max(0, (int) $saved);
        }
        return max(0, (int) Env::get('ATTACH_DEFAULT_EXPIRE_DAYS', 0));
    }

    private function fallbackEnabled(): bool
    {
        $saved = $this->setting(self::SETTING_KEY_FALLBACK_ENABLED);
        if ($saved !== '') {
            return $saved === '1';
        }
        return ((string) Env::get('ATTACH_FALLBACK_IMAGE', '1')) === '1';
    }

    private function setting(string $key): string
    {
        try {
            $this->store->ensureDictTables();
            $rows = $this->store->db()->query(
                'SELECT value FROM ' . PlatformStore::DICT_TABLE
                . ' WHERE namespace=' . $this->store->quote(self::SETTING_NS)
                . ' AND dict_key=' . $this->store->quote($key) . ' LIMIT 1'
            );
            return isset($rows[0]) ? (string) $rows[0]['value'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function putSetting(string $key, string $value, string $type): void
    {
        $this->store->ensureDictTables();
        $now = date('c');
        $exists = $this->setting($key) !== '' || $this->settingRawExists($key);
        if ($exists) {
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::DICT_TABLE
                . ' SET value=' . $this->store->quote($value)
                . ', value_type=' . $this->store->quote($type)
                . ', updated_at=' . $this->store->quote($now)
                . ' WHERE namespace=' . $this->store->quote(self::SETTING_NS)
                . ' AND dict_key=' . $this->store->quote($key)
            );
            return;
        }
        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::DICT_TABLE
            . ' (namespace, dict_key, value, value_type, owner_pkg, is_public, remark, created_at, updated_at) VALUES ('
            . $this->store->quote(self::SETTING_NS) . ', '
            . $this->store->quote($key) . ', '
            . $this->store->quote($value) . ', '
            . $this->store->quote($type) . ', '
            . $this->store->quote(self::SETTING_NS) . ', 0, '
            . $this->store->quote('附件托管服务参数') . ', '
            . $this->store->quote($now) . ', '
            . $this->store->quote($now) . ')'
        );
    }

    private function settingRawExists(string $key): bool
    {
        $rows = $this->store->db()->query(
            'SELECT id FROM ' . PlatformStore::DICT_TABLE
            . ' WHERE namespace=' . $this->store->quote(self::SETTING_NS)
            . ' AND dict_key=' . $this->store->quote($key) . ' LIMIT 1'
        );
        return isset($rows[0]);
    }

    private function isImageMime(string $mime): bool
    {
        return (bool) preg_match('#^image/#i', $mime);
    }

    private function isImageExt(string $ext): bool
    {
        return in_array(strtolower(ltrim($ext, '.')), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    private function mimeOf(string $ext): string
    {
        return match (strtolower(ltrim($ext, '.'))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'bmp'         => 'image/bmp',
            'svg'         => 'image/svg+xml',
            'pdf'         => 'application/pdf',
            'zip'         => 'application/zip',
            'txt'         => 'text/plain',
            'json'        => 'application/json',
            default       => 'application/octet-stream',
        };
    }

    private function builtinFallbackSvg(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" viewBox="0 0 320 240">'
            . '<rect width="320" height="240" fill="#f0f2f5"/>'
            . '<g fill="#c0c4cc"><circle cx="128" cy="96" r="30"/>'
            . '<path d="M70 180l58-64 40 44 30-32 52 52z"/></g>'
            . '<text x="160" y="216" font-family="sans-serif" font-size="14" fill="#909399" '
            . 'text-anchor="middle">LinHub attachment unavailable</text>'
            . '</svg>';
    }
}
