<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 删除器（T2.7 / G8）
 *
 * 清理源码/文件，高危需二次确认：confirm 必须为 true，否则抛异常。
 * 仅在 enabled/disabled 状态可删除（迁移到 absent / 移除记录）。
 */
final class PackageRemover
{
    /** @var PackageStateStore */
    private PackageStateStore $store;
    /** @var string 宿主加载目录 */
    private string $loadRoot;

    public function __construct(PackageStateStore $store, string $loadRoot)
    {
        $this->store = $store;
        $this->loadRoot = rtrim($loadRoot, '/\\');
    }

    /**
     * 删除功能包（清理源码/文件）。
     *
     * @param bool $confirmed 是否已二次确认（高危操作必传 true）
     * @throws \RuntimeException 未二次确认 / 不存在 / 状态不允许
     */
    public function remove(string $name, bool $confirmed = false): void
    {
        if (!$confirmed) {
            throw new \RuntimeException('删除为高危操作，需二次确认（确认后再次调用）');
        }
        $rec = $this->store->get($name);
        if ($rec === null) {
            throw new \RuntimeException("功能包不存在：{$name}");
        }
        $from = (string) ($rec['state'] ?? '');
        // 仅 enabled/disabled 可删除
        $to = LifecycleState::transition($from, LifecycleState::ABSENT);

        // 清理源码目录（优先加载目录；禁用中的包源码在隔离区 disabled_dir）
        $dir = $this->loadRoot . '/' . ($rec['short'] ?? '');
        if (is_dir($dir)) {
            Files::deleteTree($dir);
        } elseif (is_dir((string) ($rec['disabled_dir'] ?? ''))) {
            Files::deleteTree((string) $rec['disabled_dir']);
        } elseif (is_dir((string) ($rec['source_dir'] ?? ''))) {
            Files::deleteTree((string) $rec['source_dir']);
        }

        // 移除记录（回到 absent / 未装）
        $this->store->remove($name);
    }
}