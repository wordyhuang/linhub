<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 插件接入点注册文件持久化（T4.1–T4.7 基础）
 *
 * 以 JSON 文件存储各功能包的接入点登记结果（permissions/menus/workspace/ui_replace），
 * 每个功能包按 short 作为键；另预留保留键 {@see self::GLOBAL} 存放宿主级全局量（如界面替换生效选择、每角色默认工作台）。
 * 目录不存在时自动创建；读取/写入全程可复用，写入使用原子写（临时文件 + rename）。
 * 思路与 S2 的 PackageStateStore 一致。
 */
final class IntegrationRegistry
{
    /** 宿主级全局数据的保留键。 */
    public const GLOBAL = '_global';

    /** @var string 注册文件绝对路径 */
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
     * 读取全部接入点登记（short => 各分区数组；含保留键 _global）。
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

    /** 原子写全部登记数据。 */
    public function write(array $data): void
    {
        $this->ensureFile();
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->file);
    }

    /** 读取某键的登记（未登记返回 null）。 */
    public function get(string $key): ?array
    {
        $v = $this->read()[$key] ?? null;
        return is_array($v) ? $v : null;
    }

    /**
     * 合并写入某键的部分分区（分区级覆盖，保留该键其他分区）。
     *
     * @param array<string, mixed> $sections 形如 ['permissions' => [...], ...]
     */
    public function save(string $key, array $sections): void
    {
        $data = $this->read();
        $existing = $data[$key] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $data[$key] = array_merge($existing, $sections);
        $this->write($data);
    }

    /** 移除某键的登记（按包定向清理接入点）。 */
    public function remove(string $key): void
    {
        $data = $this->read();
        unset($data[$key]);
        $this->write($data);
    }
}