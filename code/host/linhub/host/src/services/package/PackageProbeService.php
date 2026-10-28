<?php

declare(strict_types=1);

namespace linhub\host\services\package;

use linhub\host\lifecycle\LifecycleState;
use linhub\host\lifecycle\PackageStateStore;

/**
 * 功能包探测服务实现（PackageProbeService）。
 *
 * 只读对接 PackageStateStore；构造时允许不传 store（ServiceInjector 无参友好），
 * 惰性自建默认 data/plugin-state.json 实例。所有方法异常安全：
 * 状态文件缺失/损坏时降级为「无已装包」而不抛异常。
 */
final class PackageProbeService implements PackageProbe
{
    private ?PackageStateStore $store;

    /**
     * @param PackageStateStore|null $store 注入为 null 时惰性自建默认路径实例。
     */
    public function __construct(?PackageStateStore $store = null)
    {
        $this->store = $store;
    }

    public function isInstalled(string $name): bool
    {
        $state = $this->stateOf($name);
        return $state === LifecycleState::ENABLED || $state === LifecycleState::DISABLED;
    }

    public function isEnabled(string $name): bool
    {
        return $this->stateOf($name) === LifecycleState::ENABLED;
    }

    public function versionOf(string $name): string
    {
        if (!$this->isInstalled($name)) {
            return '';
        }
        return (string) ($this->store()->get($name)['version'] ?? '');
    }

    public function snapshot(string $name): array
    {
        $rec = $this->store()->get($name);
        $state = is_array($rec) ? (string) ($rec['state'] ?? LifecycleState::ABSENT) : LifecycleState::ABSENT;
        $installed = ($state === LifecycleState::ENABLED || $state === LifecycleState::DISABLED);
        return [
            'installed' => $installed,
            'enabled'    => ($state === LifecycleState::ENABLED),
            'state'      => $state,
            'version'    => $installed ? (string) ($rec['version'] ?? '') : '',
            'short'      => $installed ? (string) ($rec['short']   ?? '') : '',
        ];
    }

    public function listInstalled(): array
    {
        try {
            return $this->store()->installed();
        } catch (\Throwable) {
            return [];
        }
    }

    private function stateOf(string $name): string
    {
        try {
            $rec = $this->store()->get($name);
        } catch (\Throwable) {
            return LifecycleState::ABSENT;
        }
        if (!is_array($rec)) {
            return LifecycleState::ABSENT;
        }
        $state = (string) ($rec['state'] ?? LifecycleState::ABSENT);
        return LifecycleState::isValid($state) ? $state : LifecycleState::ABSENT;
    }

    private function store(): PackageStateStore
    {
        return $this->store ??= new PackageStateStore($this->defaultStateFile());
    }

    private function defaultStateFile(): string
    {
        // host/src/services/package/PackageProbeService.php → 项目根（host/linhub 根 = admin/src 的上一级，即 5 层）
        $baseDir = dirname(__DIR__, 5);
        $file = getenv('PACKAGE_STATE_FILE');
        return $file !== false && $file !== ''
            ? (string) $file
            : $baseDir . '/data/plugin-state.json';
    }
}
