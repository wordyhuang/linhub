<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 功能包接入审核记录持久化（需求 F）
 *
 * 以 JSON 文件存储暂存功能包的接入审核记录，安装/升级前须存在「已通过且内容指纹未变」
 * 的审核记录，各接入节点（权限/菜单/工作台/界面替换/数据表迁移）才会放行执行。
 *
 * 记录键为 short@version（同一短名不同版本分别审核），单条记录结构：
 *  - short/title/version/dir：审核对象快照
 *  - fingerprint：审核时包目录内容指纹；当前指纹不一致则审核自动失效，需重新审核
 *  - status：approved / rejected（无记录或指纹失效由派生状态 pending/changed 表示）
 *  - reviewer/reviewed_at/comment：审核人、审核时间（ISO 8601）、审核意见
 *  - history：历次审核结论 [{status,reviewer,reviewed_at,comment}]
 *
 * 使用原子写（临时文件 + rename）降低并发破坏风险，与 PackageStateStore 一致。
 */
final class PackageReviewStore
{
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    /** 派生状态：从未审核 */
    public const PENDING = 'pending';
    /** 派生状态：曾审核（通过或驳回）但包内容已变化，结论失效 */
    public const CHANGED = 'changed';

    /** 打包/落地时排除的非交付目录（任意层级），指纹计算保持同一口径 */
    private const EXCLUDED_DIRS = ['.git', 'vendor'];

    /** @var string 审核记录文件绝对路径 */
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** 审核记录键：short@version。 */
    public static function key(string $short, string $version): string
    {
        return $short . '@' . $version;
    }

    private function ensureFile(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * 读取全部记录（short@version => record）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** 原子写全部记录。 */
    public function write(array $records): void
    {
        $this->ensureFile();
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->file);
    }

    /** 读取单条记录。 */
    public function get(string $short, string $version): ?array
    {
        return $this->read()[self::key($short, $version)] ?? null;
    }

    /** 写入/覆盖单条记录。 */
    public function save(string $short, string $version, array $record): void
    {
        $records = $this->read();
        $records[self::key($short, $version)] = $record;
        $this->write($records);
    }

    /**
     * 安装/升级门禁：该版本是否已审核通过且内容指纹未变。
     *
     * @param string $currentFingerprint 包目录当前内容指纹
     */
    public function isApproved(string $short, string $version, string $currentFingerprint): bool
    {
        $rec = $this->get($short, $version);
        return $rec !== null
            && ($rec['status'] ?? '') === self::APPROVED
            && hash_equals((string) ($rec['fingerprint'] ?? ''), $currentFingerprint);
    }

    /**
     * 派生审核状态：approved / rejected / changed / pending。
     *
     * @param string $currentFingerprint 包目录当前内容指纹
     */
    public function statusOf(string $short, string $version, string $currentFingerprint): string
    {
        $rec = $this->get($short, $version);
        if ($rec === null) {
            return self::PENDING;
        }
        if (!hash_equals((string) ($rec['fingerprint'] ?? ''), $currentFingerprint)) {
            return self::CHANGED;
        }
        return (string) ($rec['status'] ?? '') === self::APPROVED ? self::APPROVED : self::REJECTED;
    }

    /**
     * 计算暂存包目录内容指纹（SHA-256）。
     *
     * 遍历包内除 .git/vendor 外的全部文件，按相对路径排序后对「相对路径 + 文件内容」
     * 增量哈希；文件增删或任一文件内容/名称变化都会改变指纹。
     */
    public static function fingerprint(string $packageDir): string
    {
        $files = self::collectFiles($packageDir, '');
        sort($files);
        $ctx = hash_init('sha256');
        foreach ($files as $rel) {
            hash_update($ctx, $rel);
            $content = @file_get_contents(rtrim($packageDir, '/\\') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel));
            hash_update($ctx, $content === false ? '' : $content);
        }
        return hash_final($ctx);
    }

    /**
     * 递归收集相对文件路径（正斜杠分隔），排除 .git/vendor 目录。
     *
     * @return list<string>
     */
    private static function collectFiles(string $dir, string $relative): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::EXCLUDED_DIRS, true)) {
                continue;
            }
            $full = rtrim($dir, '/\\') . '/' . $entry;
            $rel  = $relative === '' ? $entry : $relative . '/' . $entry;
            if (is_dir($full)) {
                foreach (self::collectFiles($full, $rel) as $child) {
                    $out[] = $child;
                }
            } elseif (is_file($full)) {
                $out[] = $rel;
            }
        }
        return $out;
    }
}
