<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 功能包状态持久化（T2.1/T2.4 等）
 *
 * 以 JSON 状态文件存储已装功能包的记录（name/short/version/state/installed_at/source_dir/backup_dir/dependencies 等）。
 * 目录不存在时自动创建；读取/写入全程可复用。使用原子写（临时文件 + rename）降低并发破坏风险。
 */
final class PackageStateStore
{
    /** @var string 状态文件绝对路径 */
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    private function ensureFile(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * 读取全部记录（name => record）。
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
    public function get(string $name): ?array
    {
        return $this->read()[$name] ?? null;
    }

    /** 判断是否存在记录。 */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /** 已有/启用中的版本映射（供依赖解析用）：name => version。 */
    public function installed(): array
    {
        $out = [];
        foreach ($this->read() as $name => $rec) {
            $state = (string) ($rec['state'] ?? '');
            if ($state === LifecycleState::ENABLED || $state === LifecycleState::DISABLED) {
                $out[$name] = (string) ($rec['version'] ?? '');
            }
        }
        return $out;
    }

    /** 更新或新增一条记录（以 name 为准）。 */
    public function save(array $record): void
    {
        $records = $this->read();
        $records[(string) ($record['name'] ?? '')] = $record;
        $this->write($records);
    }

    /** 按 name 删除记录。 */
    public function remove(string $name): void
    {
        $records = $this->read();
        unset($records[$name]);
        $this->write($records);
    }
}