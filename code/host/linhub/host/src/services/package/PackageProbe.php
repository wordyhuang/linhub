<?php

declare(strict_types=1);

namespace linhub\host\services\package;

/**
 * 功能包探测服务契约（PackageProbe）。
 *
 * 只读出口：屏蔽 data/plugin-state.json 状态文件细节，供其他功能包与 GUI
 * 无差别查询某功能包的安装状态、版本、启用情况。任何方法均不抛异常；
 * 不存在的包按「未安装」处理。
 *
 * 状态语义（与 linhub\host\lifecycle\LifecycleState 对齐）：
 *  - ABSENT   : 无记录
 *  - ENABLED  : 已安装并启用
 *  - DISABLED : 已安装但停用（仍视为 installed=true）
 *  - DELETED  : 已删除残留（不视为 installed）
 */
interface PackageProbe
{
    /**
     * 某包当前是否「已安装」（ENABLED 或 DISABLED 均计为已装；ABSENT/DELETED 不算）。
     */
    public function isInstalled(string $name): bool;

    /**
     * 某包当前是否「已启用」（仅 ENABLED 计为 true）。
     */
    public function isEnabled(string $name): bool;

    /**
     * 已装包的版本号；未装或已删除返回空字符串。
     */
    public function versionOf(string $name): string;

    /**
     * 某包状态快照（一次性返回 installed/enabled/state/version/short）。
     * 未装时 installed=false、state=ABSENT、version=''。
     *
     * @return array{
     *   installed:bool, enabled:bool, state:string, version:string, short:string
     * }
     */
    public function snapshot(string $name): array;

    /**
     * 全部已装包的版本映射：name => version（与 DependencyResolver 使用同口径）。
     *
     * @return array<string,string>
     */
    public function listInstalled(): array;
}
