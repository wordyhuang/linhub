<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

use linhub\host\manifest\VersionConstraint;

/**
 * 依赖解析器（T2.2 / H 组 / A6）
 *
 * 读取 manifest.dependencies（{包名:版本约束}），与已装包比对：
 *   - missing：缺失依赖（需补齐）；
 *   - conflict：版本约束不满足（冲突拦截）；
 *   - satisfied：满足；
 *   - cycle：依赖环检测，检测到则阻止。
 * 返回结构化结果（missing / conflict / satisfied / cycle），不修改任何状态。
 */
final class DependencyResolver
{
    /** @var PackageStateStore 状态存储（提供已装包版本） */
    private PackageStateStore $store;

    public function __construct(PackageStateStore $store)
    {
        $this->store = $store;
    }

    /**
     * 解析依赖清单。
     *
     * @param string $name 待判断功能包名（用于环检测起点）
     * @param array<string,string> $deps 依赖清单 {包名:版本约束}
     * @return array{
     *     missing: list<string>,
     *     conflict: list<array{name:string,installed:string,required:string}>,
     *     satisfied: list<string>,
     *     cycle: bool
     * }
     */
    public function resolve(string $name, array $deps): array
    {
        $installed = $this->store->installed();
        $missing = [];
        $conflict = [];
        $satisfied = [];

        foreach ($deps as $dep => $constraint) {
            if (!isset($installed[$dep])) {
                $missing[] = $dep;
                continue;
            }
            $current = $installed[$dep];
            if (VersionConstraint::matches($current, (string) $constraint)) {
                $satisfied[] = $dep;
            } else {
                $conflict[] = ['name' => $dep, 'installed' => $current, 'required' => (string) $constraint];
            }
        }

        return [
            'missing'   => $missing,
            'conflict'  => $conflict,
            'satisfied' => $satisfied,
            'cycle'     => $this->detectCycle($name, $deps, $installed),
        ];
    }

    /**
     * 依赖环检测：以自身为根，经依赖图 DFS 是否回到自身。
     *
     * @param array<string,string> $deps 本包依赖 {包名:约束}
     * @param array<string,string> $installed 已装包 name => version
     */
    private function detectCycle(string $name, array $deps, array $installed): bool
    {
        foreach ($deps as $dep => $_c) {
            if ($dep === $name) {
                return true; // 自依赖
            }
        }
        $visited = [];
        $stack = array_keys($deps);
        while ($stack !== []) {
            $cur = array_shift($stack);
            if ($cur === $name) {
                return true;
            }
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            if (!isset($installed[$cur])) {
                // 缺失依赖暂无法展开依赖图（视为无环分支）
                continue;
            }
            // 已装包自身的依赖从状态记录读取；为简化，不做图递归展开
            $rec = $this->store->get($cur);
            foreach ((array) ($rec['dependencies'] ?? []) as $next => $_c2) {
                if (!isset($visited[$next])) {
                    $stack[] = $next;
                }
            }
        }
        return false;
    }
}