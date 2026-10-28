<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

/**
 * 禁用/启用（T2.6 / G6/G7）
 *
 * 禁用：状态 enabled → disabled，并把源码从加载目录移入隔离区（代码卸载、不可加载），
 *       同时注销该包在注册表中的全部接入点（权限/菜单/插槽/界面替换），数据保留；
 * 启用：状态 disabled → enabled，源码从隔离区移回加载目录，并重新注册接入点。
 *
 * 隔离区由 $disabledRoot 指定；未提供时退化为旧语义（仅改状态，不动源码/接入点）。
 */
final class ToggleManager
{
    /** @var PackageStateStore */
    private PackageStateStore $store;
    /** @var string 宿主加载目录 */
    private string $loadRoot;
    /** @var string|null 禁用包源码隔离区根目录 */
    private ?string $disabledRoot;
    /** @var callable|null 禁用后回调：function(string $short): void（注销接入点） */
    private $onDisable;
    /** @var callable|null 启用后回调：function(array $manifest): void（重新注册接入点） */
    private $onEnable;

    public function __construct(
        PackageStateStore $store,
        string $loadRoot,
        ?string $disabledRoot = null,
        ?callable $onDisable = null,
        ?callable $onEnable = null,
    ) {
        $this->store = $store;
        $this->loadRoot = rtrim($loadRoot, '/\\');
        $this->disabledRoot = $disabledRoot !== null ? rtrim($disabledRoot, '/\\') : null;
        $this->onDisable = $onDisable;
        $this->onEnable = $onEnable;
    }

    /**
     * 禁用功能包：卸载源码到隔离区 + 注销接入点。
     *
     * @throws \RuntimeException 不存在、状态不允许或源码卸载失败
     */
    public function disable(string $name): void
    {
        $rec = $this->mustGet($name);
        $rec['state'] = LifecycleState::transition((string) ($rec['state'] ?? ''), LifecycleState::DISABLED);

        $short = (string) ($rec['short'] ?? '');
        $src   = $this->loadRoot . '/' . $short;
        $moved = false;
        if ($this->disabledRoot !== null && $short !== '' && is_dir($src)) {
            $dst = $this->disabledRoot . '/' . $short;
            if (is_dir($dst)) {
                Files::deleteTree($dst); // 清理上一次残留的隔离目录，保证幂等
            }
            if (!is_dir($this->disabledRoot)) {
                mkdir($this->disabledRoot, 0775, true);
            }
            if (!@rename($src, $dst)) {
                throw new \RuntimeException("禁用失败：无法将源码移入隔离区 {$dst}");
            }
            $rec['disabled_dir'] = $dst;
            $moved = true;
        }

        try {
            if ($this->onDisable !== null && $short !== '') {
                ($this->onDisable)($short);
            }
        } catch (\Throwable $e) {
            // 回滚：把源码移回加载目录，保持状态一致
            if ($moved) {
                @rename((string) $rec['disabled_dir'], $src);
                unset($rec['disabled_dir']);
            }
            throw new \RuntimeException('禁用失败：注销接入点异常 - ' . $e->getMessage());
        }

        $this->store->save($rec);
    }

    /**
     * 启用功能包：源码从隔离区移回 + 重新注册接入点。
     *
     * @throws \RuntimeException 不存在、状态不允许或源码恢复失败
     */
    public function enable(string $name): void
    {
        $rec = $this->mustGet($name);
        $rec['state'] = LifecycleState::transition((string) ($rec['state'] ?? ''), LifecycleState::ENABLED);

        $short = (string) ($rec['short'] ?? '');
        $src   = $this->loadRoot . '/' . $short;
        $quar  = (string) ($rec['disabled_dir'] ?? '');
        $restored = false;
        if ($quar !== '' && is_dir($quar)) {
            if (is_dir($src)) {
                throw new \RuntimeException("启用失败：加载目录已存在 {$src}，无法恢复");
            }
            if (!@rename($quar, $src)) {
                throw new \RuntimeException("启用失败：无法从隔离区恢复源码到 {$src}");
            }
            unset($rec['disabled_dir']);
            $restored = true;
        }

        try {
            if ($this->onEnable !== null && $short !== '' && is_dir($src)) {
                $manifest = is_file($src . '/manifest.php') ? (array) include $src . '/manifest.php' : [];
                if ($manifest !== []) {
                    ($this->onEnable)($manifest);
                }
            }
        } catch (\Throwable $e) {
            // 回滚：已恢复的源码移回隔离区
            if ($restored) {
                if (!is_dir(dirname($quar))) {
                    mkdir(dirname($quar), 0775, true);
                }
                @rename($src, $quar);
                $rec['disabled_dir'] = $quar;
            }
            throw new \RuntimeException('启用失败：重新注册接入点异常 - ' . $e->getMessage());
        }

        $this->store->save($rec);
    }

    /** @throws \RuntimeException 功能包不存在 */
    private function mustGet(string $name): array
    {
        $rec = $this->store->get($name);
        if ($rec === null) {
            throw new \RuntimeException("功能包不存在：{$name}");
        }
        return $rec;
    }
}
