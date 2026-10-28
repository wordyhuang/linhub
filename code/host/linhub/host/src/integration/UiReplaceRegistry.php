<?php

declare(strict_types=1);

namespace linhub\host\integration;

/**
 * 界面替换声明扫描与裁决（T4.5 / L2 / L3，T4.6 / L4 / L5 / L6，T4.7 / L7 / L8）
 *
 * - 扫描各包 ui_replace.php 候选；校验 target 属平台可替换页面清单（复用 M007 语义）。
 * - 非法 target 被标记（valid=false）且页面回退默认（L7/L8）。
 * - 同 target 多包竞争按 priority（数值大优先）整页替换（L6）。
 * - 超管可显式选择生效包（choose，L4/L5），选择后优先于优先级裁决。
 * - 目标页在平台清单缺失、无有效候选或未选择生效时回退平台默认页（L8）。
 */
final class UiReplaceRegistry
{
    /** 分区键 */
    private const SECTION = 'ui_replace';
    /** 全局生效选择存储键 */
    private const ACTIVE_KEY = 'active_ui';

    private IntegrationRegistry $store;
    /** @var list<string> 平台可替换页面清单 */
    private array $replaceablePages;

    /** @param list<string> $replaceablePages 平台发布的"可替换页面清单" */
    public function __construct(IntegrationRegistry $store, array $replaceablePages = [])
    {
        $this->store = $store;
        $this->replaceablePages = $replaceablePages;
    }

    public function setReplaceablePages(array $pages): void
    {
        $this->replaceablePages = $pages;
    }

    /**
     * 扫描并登记一个功能包的界面替换候选（L2/L3）。
     *
     * @param array<int, array<string, mixed>> $candidates ui_replace.php 返回的候选数组
     * @return list<array<string, mixed>> 规范化后的候选（含 valid 与 short）
     */
    public function registerCandidates(string $short, array $candidates): array
    {
        $list = [];
        foreach ($candidates as $c) {
            $target = (string) ($c['target'] ?? '');
            $valid = $target !== '' && in_array($target, $this->replaceablePages, true);
            $list[] = [
                'target'   => $target,
                'priority' => (int) ($c['priority'] ?? 0),
                'view'     => (string) ($c['view'] ?? ''),
                'label'    => (string) ($c['label'] ?? ''),
                'valid'    => $valid,
                'short'    => $short,
            ];
        }
        $this->store->save($short, [self::SECTION => $list]);
        return $list;
    }

    /**
     * 全部已扫描候选（合并各包）。
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(): array
    {
        $out = [];
        foreach ($this->store->read() as $short => $sections) {
            if ($short === IntegrationRegistry::GLOBAL) {
                continue;
            }
            foreach ((array) ($sections[self::SECTION] ?? []) as $c) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * 被标记为非法的 target（不在平台可替换页面清单），供 L2 扫描结果展示。
     *
     * @return list<string>
     */
    public function invalidTargets(): array
    {
        $targets = [];
        foreach ($this->candidates() as $c) {
            if (!$c['valid'] && !in_array($c['target'], $targets, true)) {
                $targets[] = (string) $c['target'];
            }
        }
        return $targets;
    }

    /**
     * 超管显式选择某包替换某 target（L4/L5）。选择键持久化到宿主全局区。
     *
     * @throws \RuntimeException 该包对 target 无有效候选时拒绝选择
     */
    public function choose(string $target, string $short): void
    {
        $found = false;
        foreach ($this->candidates() as $c) {
            if ($c['target'] === $target && $c['short'] === $short && !empty($c['valid'])) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \RuntimeException("不允许选择：包 {$short} 对 target {$target} 无有效替换候选");
        }
        $global = $this->store->get(IntegrationRegistry::GLOBAL) ?? [];
        $active = (array) ($global[self::ACTIVE_KEY] ?? []);
        $active[$target] = $short;
        $this->store->save(IntegrationRegistry::GLOBAL, [self::ACTIVE_KEY => $active]);
    }

    /** 读取超管对某 target 的当前生效选择；未选择返回 null。 */
    public function activeChoice(string $target): ?string
    {
        $global = $this->store->get(IntegrationRegistry::GLOBAL) ?? [];
        $v = $global[self::ACTIVE_KEY][$target] ?? '';
        return $v === '' ? null : (string) $v;
    }

    /**
     * 清空某 target 的显式选择，使其回退裁决逻辑（priority/默认页，L8 兜底语义）。
     */
    public function clearChoice(string $target): void
    {
        $global = $this->store->get(IntegrationRegistry::GLOBAL) ?? [];
        $active = (array) ($global[self::ACTIVE_KEY] ?? []);
        if (!array_key_exists($target, $active)) {
            return;
        }
        unset($active[$target]);
        $this->store->save(IntegrationRegistry::GLOBAL, [self::ACTIVE_KEY => $active]);
    }

    /**
     * 裁决某 target 的实际生效替换（L6/L8）。
     * 规则：target 不在平台清单 / 无有效候选 => 返回 null（回退平台默认页）；
     * 否则先看超管显式选择，再看同 target 有效候选的 priority（数值大优先，并列取先登记）。
     *
     * @return array<string, mixed>|null 生效候选；null 表示回退默认页
     */
    public function resolve(string $target): ?array
    {
        if (!in_array($target, $this->replaceablePages, true)) {
            return null; // 目标页不在可替换清单 => 默认回退（L7/L8）
        }
        $valid = array_values(array_filter(
            $this->candidates(),
            fn (array $c): bool => !empty($c['valid']) && $c['target'] === $target,
        ));
        if ($valid === []) {
            return null; // 无有效候选 => 默认回退
        }

        // 超管显式选择优先（L4/L5）
        $chosen = $this->activeChoice($target);
        if ($chosen !== null) {
            foreach ($valid as $c) {
                if ($c['short'] === $chosen) {
                    return $c;
                }
            }
        }

        // 同 target 多包按 priority 裁决（L6），并列取先登记
        usort($valid, fn (array $a, array $b): int => ((int) $b['priority']) <=> ((int) $a['priority']));
        return $valid[0];
    }
}