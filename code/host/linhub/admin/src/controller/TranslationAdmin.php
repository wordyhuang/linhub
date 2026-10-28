<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\translation\TranslatorService;

/**
 * 翻译管理超管页（TR 管理 GUI）—— admin 应用，仅超管
 *
 *  - GET  /admin/translation_admin/index     页面空壳
 *  - GET  /admin/translation_admin/rows      分页检索 JSON（namespace/lang/keyword）
 *  - GET  /admin/translation_admin/options   命名空间与语言选项（stats 聚合）
 *  - GET  /admin/translation_admin/missing   缺失对照（基准语言有、对照语言缺的 key）
 *  - POST /admin/translation_admin/save      新增 / 修改词条（owner_pkg=host）
 *  - POST /admin/translation_admin/delete    删除单条词条
 *
 * 超管可跨功能包命名空间读写；写入按 asAdmin=true 落 owner_pkg=host（P1 提示见视图）。
 */
class TranslationAdmin extends AdminController
{
    private function service(): TranslatorService
    {
        return new TranslatorService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('translation_admin/index');
    }

    /** JSON：全量翻译分页（超管视角，跨命名空间）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['namespace', 'lang', 'keyword'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        $svc = $this->service();
        try {
            $result = $svc->search($filter, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $svc->stats(),
        ]);
    }

    /** JSON：命名空间与语言选项（筛选用）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            return $this->jsonOk($this->service()->stats());
        } catch (\Throwable $e) {
            return $this->jsonFail('汇总失败：' . $e->getMessage());
        }
    }

    /** JSON：缺失对照——基准语言有、对照语言缺的 key 清单。 */
    public function missing()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $namespace = trim((string) $req->get('namespace', ''));
        $baseLang = trim((string) $req->get('base_lang', 'zh-CN'));
        $compareLang = trim((string) $req->get('compare_lang', 'en-US'));
        if ($namespace === '') {
            return $this->jsonFail('缺少 namespace');
        }
        if ($baseLang === '' || $compareLang === '' || $baseLang === $compareLang) {
            return $this->jsonFail('请选择不同的基准语言与对照语言');
        }
        try {
            $items = $this->service()->missing($namespace, $baseLang, $compareLang);
        } catch (\Throwable $e) {
            return $this->jsonFail('对照失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'namespace'    => $namespace,
            'base_lang'    => $baseLang,
            'compare_lang' => $compareLang,
            'total'        => count($items),
            'list'         => $items,
        ]);
    }

    /** 新增或修改单条词条（超管写入，owner_pkg=host）。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $namespace = trim((string) $req->post('namespace', ''));
        $key = trim((string) $req->post('key', ''));
        $lang = trim((string) $req->post('lang', ''));
        $value = (string) $req->post('value', '');
        $isPublic = (int) $req->post('is_public', 0) === 1;
        if ($namespace === '' || $key === '' || $lang === '') {
            return $this->jsonFail('缺少 namespace、key 或 lang');
        }
        try {
            $row = $this->service()->put($namespace, $key, $lang, $value, ['is_public' => $isPublic], true);
            $this->audit($gate, 'translation.save', $namespace . '/' . $key . '/' . $lang);
            return $this->jsonOk($row, '翻译词条已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 删除单条词条（超管删除）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $namespace = trim((string) $req->post('namespace', ''));
        $key = trim((string) $req->post('key', ''));
        $lang = trim((string) $req->post('lang', ''));
        if ($namespace === '' || $key === '' || $lang === '') {
            return $this->jsonFail('缺少 namespace、key 或 lang');
        }
        try {
            $removed = $this->service()->remove($namespace, $key, $lang, true);
            if (!$removed) {
                return $this->jsonFail('词条不存在', 404);
            }
            $this->audit($gate, 'translation.delete', $namespace . '/' . $key . '/' . $lang);
            return $this->jsonOk(null, '翻译词条已删除');
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
    }

    /** 超管操作留痕，失败不阻断。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
