<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\approval\ApprovalService;

/**
 * 平台审批中心（AP 管理 GUI）—— admin 应用，仅超管
 *
 *  - GET  /admin/approval_admin/index        全部单据页（平台全量检索 + 服务统计）
 *  - GET  /admin/approval_admin/todo         我的待办页
 *  - GET  /admin/approval_admin/done         我的已办页
 *  - GET  /admin/approval_admin/detail       单据详情页
 *  - GET  /admin/approval_admin/rows         全部单据分页检索 JSON + stats
 *  - GET  /admin/approval_admin/pendingRows  我的待办 JSON（按审批人快照跨包检索）
 *  - GET  /admin/approval_admin/handledRows  我的已办 JSON
 *  - GET  /admin/approval_admin/detailData   单据详情 JSON（单据 + 节点 + 动作 + can_act）
 *  - POST /admin/approval_admin/approve      同意（意见选填）
 *  - POST /admin/approval_admin/reject       驳回（意见必填，整单驳回）
 *  - POST /admin/approval_admin/cancel       撤回（仅 pending）
 *
 * 身份一律取当前登录态构造 Identity 传入服务层，与功能包侧调用契约完全同一条链路（D8 双入口一致性）。
 */
class ApprovalAdmin extends AdminController
{
    private function service(): ApprovalService
    {
        return new ApprovalService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/index');
    }

    public function todo()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/todo');
    }

    public function done()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/done');
    }

    public function detail()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/detail');
    }

    /** JSON：全部单据（超管视角跨包全量）+ 用量统计。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $filter = [];
        foreach (['status', 'flow_key', 'submitter_id', 'keyword', 'date_from', 'date_to', 'orphaned', 'package'] as $key) {
            $value = trim((string) $this->request->get($key, ''));
            if ($value !== '') {
                $filter[$key] = $value;
            }
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));

        try {
            $result = $this->service()->list(null, $filter, $page, $size);
            $stats = $this->service()->stats();
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $stats,
        ]);
    }

    /** JSON：我的待办。 */
    public function pendingRows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        try {
            $result = $this->service()->myPending($gate, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：我的已办。 */
    public function handledRows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        try {
            $result = $this->service()->myHandled($gate, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：单据详情（payload / 节点链 / 动作时间线 / 当前用户可否审批）。 */
    public function detailData()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->get('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        try {
            $row = $this->service()->get(null, $no);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
        if ($row === null) {
            return $this->jsonFail('单据不存在', 404);
        }
        $row['can_act'] = $this->service()->canAct($gate, $no);
        return $this->jsonOk($row);
    }

    /** 同意（意见选填）。 */
    public function approve()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        try {
            $this->service()->approve($gate, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('审批失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.approve', $no);
        return $this->jsonOk(null, '已同意');
    }

    /** 驳回（意见必填，整单驳回）。 */
    public function reject()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        if ($comment === '') {
            return $this->jsonFail('驳回必须填写审批意见');
        }
        try {
            $this->service()->reject($gate, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('驳回失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.reject', $no);
        return $this->jsonOk(null, '已驳回');
    }

    /** 撤回（仅 pending 且未冻结）。 */
    public function cancel()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        try {
            $this->service()->cancelAs($gate, null, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('撤回失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.cancel', $no);
        return $this->jsonOk(null, '已撤回');
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
