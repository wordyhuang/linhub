<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\printtpl\PrintTplService;

/**
 * 单据打印模板管理超管页（PT 管理 GUI）—— admin 应用，仅超管
 *
 *  - GET  /admin/printtpl_admin/index          页面空壳
 *  - GET  /admin/printtpl_admin/rows           模板分页检索（keyword/scope/status/owner_pkg）+ 配额统计
 *  - GET  /admin/printtpl_admin/detail         模板详情（正文/纸张/样例数据，编制页载入）
 *  - POST /admin/printtpl_admin/save           新增/编辑（编号唯一不可改；保存即版本快照）
 *  - POST /admin/printtpl_admin/toggle         启停
 *  - POST /admin/printtpl_admin/delete         删除（连同版本快照）
 *  - POST /admin/printtpl_admin/copy           复制为新编号
 *  - GET  /admin/printtpl_admin/versions       版本快照列表（最近 10 版）
 *  - POST /admin/printtpl_admin/rollback       回滚到指定版本
 *  - GET  /admin/printtpl_admin/print          试打：按 id + 样例数据输出完整打印文档（HTML 直出）
 *  - POST /admin/printtpl_admin/preview        编辑页预览未落库内容（HTML 直出）
 *  - GET  /admin/printtpl_admin/printTicket    统一打印入口：消费一次性票据并输出打印文档（功能包 printUrl 跳转目标）
 *
 * 试打/预览/统一打印均为 HTML 直出（浏览器渲染 + @page 分页），不走 JSON；
 * 管理写操作全部 auditTrail 留痕（PT-19），失败不阻断。
 */
class PrintTplAdmin extends AdminController
{
    private function service(): PrintTplService
    {
        return PrintTplService::instance();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('printtpl_admin/index');
    }

    /** 可视化编辑器页（?id= 编辑已有模板；缺省为新建）。 */
    public function editor()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('printtpl_admin/editor');
    }

    /** JSON：模板分页检索 + 配额统计。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        try {
            $result = $this->service()->listRows(
                trim((string) $req->get('keyword', '')),
                trim((string) $req->get('scope', '')),
                trim((string) $req->get('status', '')),
                trim((string) $req->get('owner_pkg', '')),
                max(1, (int) $req->get('page', 1)),
                max(1, min(100, (int) $req->get('size', 20))),
            );
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['rows'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => max(1, (int) $req->get('page', 1)),
            'pageSize'    => max(1, min(100, (int) $req->get('size', 20))),
            'counts'      => $this->service()->counts(),
        ]);
    }

    /** JSON：模板详情（含正文/纸张/样例数据）。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            return $this->jsonFail('缺少模板 id');
        }
        try {
            return $this->jsonOk($this->service()->detail($id));
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
    }

    /** JSON：新增/编辑保存（编号唯一且创建后不可改；每次保存留快照）。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req  = $this->request;
        $id   = (int) $req->post('id', 0);
        $in   = [
            'code'        => trim((string) $req->post('code', '')),
            'name'        => trim((string) $req->post('name', '')),
            'scope'       => trim((string) $req->post('scope', 'platform')),
            'owner_pkg'   => trim((string) $req->post('owner_pkg', '')),
            'mode'        => trim((string) $req->post('mode', 'visual')),
            'body'        => (string) $req->post('body', ''),
            'paper'       => json_decode((string) $req->post('paper', ''), true) ?: [],
            'sample_data' => json_decode((string) $req->post('sample_data', ''), true) ?: [],
        ];
        try {
            $r = $this->service()->save($id > 0 ? $id : null, $in, (string) $gate->name);
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
        $this->audit($gate, 'printtpl.save', '#' . $r['id'] . ' ' . $in['name']);
        return $this->jsonOk($r, '模板已保存（版本 v' . $r['version_no'] . '）');
    }

    /** JSON：启停。 */
    public function toggle()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 1);
        if ($id <= 0) {
            return $this->jsonFail('缺少模板 id');
        }
        try {
            $this->service()->toggle($id, $status);
        } catch (\Throwable $e) {
            return $this->jsonFail('操作失败：' . $e->getMessage());
        }
        $this->audit($gate, 'printtpl.toggle', '#' . $id . ' status=' . ($status === 1 ? 'on' : 'off'));
        return $this->jsonOk(null, $status === 1 ? '模板已启用' : '模板已停用');
    }

    /** JSON：删除（连同版本快照）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return $this->jsonFail('缺少模板 id');
        }
        try {
            $this->service()->del($id);
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
        $this->audit($gate, 'printtpl.delete', '#' . $id);
        return $this->jsonOk(null, '模板已删除');
    }

    /** JSON：复制为新编号。 */
    public function copy()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id      = (int) $this->request->post('id', 0);
        $newCode = strtolower(trim((string) $this->request->post('new_code', '')));
        if ($id <= 0 || $newCode === '') {
            return $this->jsonFail('缺少模板 id 或新编号');
        }
        try {
            $newId = $this->service()->copy($id, $newCode, (string) $gate->name);
        } catch (\Throwable $e) {
            return $this->jsonFail('复制失败：' . $e->getMessage());
        }
        $this->audit($gate, 'printtpl.copy', '#' . $id . ' -> ' . $newCode);
        return $this->jsonOk(['id' => $newId], '已复制为新模板');
    }

    /** JSON：版本快照列表。 */
    public function versions()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            return $this->jsonFail('缺少模板 id');
        }
        try {
            return $this->jsonOk(['list' => $this->service()->versions($id)]);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
    }

    /** JSON：回滚到指定版本。 */
    public function rollback()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id  = (int) $this->request->post('id', 0);
        $ver = (int) $this->request->post('version_no', 0);
        if ($id <= 0 || $ver <= 0) {
            return $this->jsonFail('缺少模板 id 或版本号');
        }
        try {
            $this->service()->rollback($id, $ver, (string) $gate->name);
        } catch (\Throwable $e) {
            return $this->jsonFail('回滚失败：' . $e->getMessage());
        }
        $this->audit($gate, 'printtpl.rollback', '#' . $id . ' v' . $ver);
        return $this->jsonOk(null, '已回滚到 v' . $ver . '（当前内容已另存新版本）');
    }

    /** 试打（PT-2）：按 id + 样例数据输出完整打印文档（HTML 直出，浏览器打印）。 */
    public function print()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            return response('缺少模板 id', 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        try {
            $html = $this->service()->renderById($id, true);
            $this->service()->markTested($id);
            $this->audit($gate, 'printtpl.testPrint', '#' . $id);
        } catch (\Throwable $e) {
            return response('试打失败：' . $e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 编辑页预览（未落库内容）：form POST body/paper/sample_data → HTML 直出。 */
    public function preview()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req    = $this->request;
        $body   = (string) $req->post('body', '');
        $paper  = json_decode((string) $req->post('paper', ''), true) ?: [];
        $sample = json_decode((string) $req->post('sample_data', ''), true) ?: [];
        try {
            $html = $this->service()->renderDraft($body, $paper, $sample);
        } catch (\Throwable $e) {
            return response('预览失败：' . $e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 统一打印入口（T-7）：消费一次性票据 → 渲染输出打印文档。
     * 功能包 printUrl() 返回的地址即指向这里；票据 5 分钟内单次有效。
     */
    public function printTicket()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $token = trim((string) $this->request->get('token', ''));
        if ($token === '') {
            return response('缺少打印票据', 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        try {
            $t    = $this->service()->consumeTicket($token);
            $html = $this->service()->render(
                ((string) ($t['owner_pkg'] ?? '')) !== '' ? (string) $t['owner_pkg'] : null,
                (string) $t['tpl_code'],
                is_array($t['data'] ?? null) ? $t['data'] : [],
            );
            $this->audit($gate, 'printtpl.ticketPrint', (string) $t['tpl_code']);
        } catch (\Throwable $e) {
            return response('打印失败：' . $e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
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
