<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

/**
 * 本地磁盘存储驱动（AT 存储工厂默认实现）。
 *
 * 所有对象统一落在私有根目录（默认 code/host/data/attach，持久业务数据区），不直接暴露为静态资源：
 *  - 公开附件：凭 token 走免鉴权下载控制器（附件服务先校验 is_public 再吐字节）；
 *  - 私有附件：凭 token 走鉴权下载控制器（附件服务校验归属/超管）。
 * 这样公开与私有只有权限差异，物理布局完全一致，切驱动也不影响 URL 约定。
 */
final class LocalDriver implements StorageInterface
{
    private string $root;
    private string $publicBase;

    public function __construct(string $root = '', string $publicBase = '/attachment')
    {
        $root = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root);
        $this->root = rtrim($root !== '' ? $root : $this->defaultRoot(), DIRECTORY_SEPARATOR);
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0775, true);
        }
        $this->publicBase = '/' . trim($publicBase, '/');
    }

    /** 默认根目录：code/host/data/attach（持久业务数据区，勿放 runtime 以免被 think clear 误清）。 */
    public static function defaultRoot(): string
    {
        // 本文件位于 code/host/linhub/host/src/services/attachment，根目录向上 5 级到 code/host
        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'attach';
    }

    public function name(): string
    {
        return 'local';
    }

    /** 私有根目录（清理孤儿对象时由附件服务使用）。 */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * 递归列出根目录内全部对象相对 key（供孤儿清理比对）。
     *
     * @return list<string>
     */
    public function listKeys(): array
    {
        $keys = [];
        if (!is_dir($this->root)) {
            return $keys;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $relative = ltrim(str_replace(
                    [$this->root, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $file->getPathname(),
                ), '/');
                $keys[] = $relative;
            }
        }
        return $keys;
    }

    public function put(string $key, string $content): string
    {
        $path = $this->fullPath($key);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("本地附件写入失败：{$key}");
        }
        return $key;
    }

    public function get(string $key): string
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            throw new \RuntimeException("附件不存在：{$key}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("附件读取失败：{$key}");
        }
        return $content;
    }

    public function exists(string $key): bool
    {
        return is_file($this->fullPath($key));
    }

    public function delete(string $key): void
    {
        $path = $this->fullPath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function url(string $key, bool $public = true): string
    {
        // 公开与私有统一走下载入口，由附件服务按 is_public 与登录态裁决；token 即 key 的末段
        return $this->publicBase . '/download?token=' . rawurlencode(basename($key));
    }

    public function ping(): array
    {
        if (is_dir($this->root) && is_writable($this->root)) {
            return ['ok' => true, 'message' => '本地存储目录可写：' . $this->root];
        }
        return ['ok' => false, 'message' => '本地存储目录不可写：' . $this->root];
    }

    /** 相对 key → 绝对路径，并阻断路径穿越。 */
    private function fullPath(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if (str_contains($key, '..')) {
            throw new \InvalidArgumentException('非法的附件对象 key');
        }
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }
}
