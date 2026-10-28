<?php

declare(strict_types=1);

namespace linhub\host\rbac;

/**
 * 通用 JSON 文件存储（S5 基础层）
 *
 * 以单个 JSON 文件持久化数据，目录不存在时自动创建；写入采用原子写（临时文件 + rename）。
 * 读写全程可复用，供 RbacStorage / AuditLog 等存储类共享同一套实现。
 * 文件路径由构造传入，不硬编码（验证时可指向 temp/）。
 */
class JsonFileStore
{
    /** @var string 存储文件绝对路径 */
    protected string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * 读取全部数据。
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw  = @file_get_contents($this->file);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** 原子写全部数据。 */
    public function write(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->file);
    }

    /**
     * 读取一个分区（未登记返回空数组）。
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $v = $this->read()[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    /** 覆盖写入一个分区。 */
    public function putSection(string $key, array $value): void
    {
        $data         = $this->read();
        $data[$key]   = $value;
        $this->write($data);
    }

    /** 删除整个分区。 */
    public function dropSection(string $key): void
    {
        $data = $this->read();
        unset($data[$key]);
        $this->write($data);
    }
}