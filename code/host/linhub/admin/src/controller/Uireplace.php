<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\support\HostBootstrap;

/**
 * 界面替换管理 GUI（G7 / T4.5 / T4.6）—— admin 应用
 *
 *  - GET  /admin/uireplace/index   替换候选总览空壳（前端拉取 rows 渲染）
 *  - GET  /admin/uireplace/rows    替换候选总览 JSON（按 target 分组，含非法 target 红区）
 *  - POST /admin/uireplace/choose  超管选择生效包 / 回退平台默认页（JSON）
 *
 * 候选来源 UiReplaceRegistry::candidates()（各包 ui_replace.php 声明，含 valid 校验）；
 * 非法 target（不在平台可替换页面清单）单独红区列出，且不可选（G7.2 L7）。
 * 选择生效走 choose()，回退默认页走 clearChoice()（G7.3 L8 兜底），均记审计 uireplace.choose。
 */
class Uireplace extends AdminController
{
    /** 替换候选总览空壳：前端按 workdesktop-ai 写法拉取 rows 渲染。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('uireplace/index');
    }

    /** 替换候选总览 JSON：按 target 分组候选与生效标记，红区列出非法 target。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $reg = $this->bootstrap->uiReplace();

        // 按 target 分组候选；区分合法组与非法 target 红区
        $targetGroups = [];
        $invalidTargets = $reg->invalidTargets();
        $invalidSet = [];
        foreach ($invalidTargets as $t) {
            $invalidSet[$t] = true;
        }
        foreach ($reg->candidates() as $c) {
            $target = (string) ($c['target'] ?? '');
            if ($target === '') {
                continue;
            }
            $entry = [
                'short'    => (string) ($c['short'] ?? ''),
                'priority' => (int) ($c['priority'] ?? 0),
                'view'     => (string) ($c['view'] ?? ''),
                'label'    => (string) ($c['label'] ?? ''),
                'valid'    => !empty($c['valid']),
            ];
            if (isset($invalidSet[$target])) {
                $targetGroups[$target]['invalid'] = true;
            }
            $targetGroups[$target]['target'] = $target;
            $targetGroups[$target]['candidates'][] = $entry;
        }

        // 各 target 当前生效选择与裁决结果
        $activeMap = [];
        foreach (array_keys($targetGroups) as $t) {
            $chosen = $reg->activeChoice($t);
            $resolved = $reg->resolve($t);
            $activeMap[$t] = [
                'chosen'  => $chosen,
                'resolved' => $resolved === null ? null : (string) ($resolved['short'] ?? ''),
            ];
        }
        // 稳定输出顺序：合法组在前，非法红区在后
        $groups = [];
        foreach ($targetGroups as $t => $g) {
            usort($g['candidates'], fn ($a, $b) => ((int) $b['priority']) <=> ((int) $a['priority']));
            $g['active'] = $activeMap[$t] ?? ['chosen' => null, 'resolved' => null];
            $g['invalid'] = !empty($g['invalid']);
            $groups[] = $g;
        }
        usort($groups, fn ($a, $b) => (int) (empty($a['invalid'])) <=> (int) (empty($b['invalid']))
            ?: strcmp((string) $a['target'], (string) $b['target']));

        return $this->jsonOk([
            'groups' => $groups,
            'replaceablePages' => array_values(HostBootstrap::REPLACEABLE_PAGES),
        ]);
    }

    /** 选择生效包（或回退平台默认页），记审计 uireplace.choose。 */
    public function choose()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $identity = $gate;

        $target = trim((string) $this->request->post('target', ''));
        $short  = trim((string) $this->request->post('short', ''));
        if ($target === '') {
            return $this->jsonFail('缺少替换目标页面', 422);
        }

        $reg = $this->bootstrap->uiReplace();
        try {
            if ($short === '') {
                $reg->clearChoice($target);
                $this->bootstrap->auditTrail()->log('uireplace.choose', $target, (string) $identity->name, ['short' => null, 'action' => 'reset']);
                return $this->jsonOk(null, '已回退平台默认页');
            }
            $reg->choose($target, $short);
            $this->bootstrap->auditTrail()->log('uireplace.choose', $target, (string) $identity->name, ['short' => $short, 'action' => 'choose']);
            return $this->jsonOk(null, "已选择 {$short} 替换 {$target}");
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }
}