<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use Lg\Pack\LinCrm\LinCrmEvent;
use Lg\Pack\LinCrm\Model\Contract;
use Lg\Pack\LinCrm\Service\CodeService;
use think\facade\Db;
use think\Request;

/**
 * 合同与回款（FR-CON-01~05/07，M2；⛔OI-001 契约签署前不涉及事件订阅联动）
 */
class ContractController extends LinCrmBaseController
{
    private Contract $m;

    public function __construct() { $this->m = new Contract(); }

    /** 合同管理页/应收台账页（GUI，菜单入口；同页复用，视图按路径自动切应收视角） */
    public function pageContract()
    {
        $this->checkPerm('lin-crm:contract:view');
        return $this->render('contract/index');
    }

    /** 合同列表 JSON（FR-CON-01） */
    public function rows(Request $req)
    {
        $this->checkPerm('lin-crm:contract:view');
        $q = $this->m->q();
        $this->scope($q);
        foreach (['status', 'customer_id'] as $f) {
            if ($v = $this->arg($f)) { $q->where($f, $v); }
        }
        if ($kw = trim((string) $this->arg('keyword', ''))) {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('name', '%' . $kw . '%')->whereOr('code', 'like', '%' . $kw . '%');
            });
        }
        return $this->ok($this->pageShape($q->order('updated_at', 'desc')->paginate((int) ($this->arg('page_size', 20)))));
    }

    /** 回款计划台账 JSON（应收台账页数据源；按合同负责人行权限） */
    public function planRows(Request $req)
    {
        $this->checkPerm('lin-crm:contract:view');
        $q = \think\facade\Db::name('lg_lin-crm_receivable_plan')->alias('p')
            ->join('`lg_lin-crm_contract` c', 'c.id = p.contract_id AND c.deleted_at IS NULL')
            ->field('p.*, c.code AS contract_code, c.name AS contract_name');
        $this->scope($q, 'c');
        if ($v = $this->arg('status')) { $q->where('p.status', $v); }
        return $this->ok($this->pageShape($q->order('p.plan_date', 'asc')->paginate((int) ($this->arg('page_size', 20)))));
    }

    /** 新建/编辑草稿（FR-CON-01：挂客户与商机、有效期、附件经 attachment 表） */
    public function save(Request $req)
    {
        $this->checkPerm('lin-crm:contract:create');
        $customerId = (int) $req->param('customer_id');
        if (!$customerId) { return $this->fail('合同必须挂有效客户'); }
        $data = [
            'customer_id' => $customerId,
            'opportunity_id' => (int) $req->param('opportunity_id', 0),
            'name' => (string) $req->param('name'),
            'amount' => (float) $req->param('amount', 0),
            'sign_date' => $req->param('sign_date') ?: null,
            'effective_from' => $req->param('effective_from') ?: null,
            'effective_to' => $req->param('effective_to') ?: null,
            'owner_id' => (int) ($req->param('owner_id') ?: $this->currentUserId()),
            'status' => 'draft', 'updated_at' => now_fmt(),
        ];
        if ($id = (int) $req->param('id', 0)) {
            $this->m->q()->where('id', $id)->where('status', 'draft')->update($data);
            return $this->ok(['id' => $id]);
        }
        $data['code'] = CodeService::next('contract');
        $id = (int) Db::name('lg_lin-crm_contract')->insertGetId($data);
        return $this->ok(['id' => $id]);
    }

    /** 提交审批（FR-CON-02：require_audit=false 免审直达 effective） */
    public function submit(Request $req)
    {
        $this->checkPerm('lin-crm:contract:create');
        $id = (int) $req->param('id');
        $requireAudit = (bool) (config('crm.contract.require_audit') ?? true);
        $to = $requireAudit ? 'auditing' : 'effective';
        $hit = $this->m->q()->where('id', $id)->where('status', 'draft')->update(['status' => $to, 'updated_at' => now_fmt()]);
        if (!$hit) { return $this->fail('仅草稿状态可提交'); }
        if ($to === 'effective') { $this->publish(LinCrmEvent::CONTRACT_AUDIT_RESULT, ['id' => $id, 'result' => 'auto_effective']); }
        return $this->ok(null, $requireAudit ? '已提交审批' : '免审生效');
    }

    /** 审批（FR-CON-02：通过→effective；驳回→draft 且意见必填；发 EVT-PUB-05）。注：方法名 approve，避免与基类 audit() 审计留痕撞名；URL 仍为 crm/contract/audit */
    public function approve(Request $req)
    {
        $this->checkPerm('lin-crm:contract:audit');
        $id = (int) $req->param('id');
        $approve = (bool) $req->param('approve');
        $opinion = (string) $req->param('opinion');
        if (!$approve && !$opinion) { return $this->fail('驳回必须填写审批意见'); }
        $to = $approve ? 'effective' : 'draft';
        $hit = $this->m->q()->where('id', $id)->where('status', 'auditing')->update([
            'status' => $to, 'audit_user_id' => $this->currentUserId(),
            'audit_opinion' => $opinion, 'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('合同不在审批中'); }
        $ownerId = (int) $this->m->q()->where('id', $id)->value('owner_id');
        $this->notify($ownerId, '合同审批' . ($approve ? '通过' : '驳回'), '意见：' . $opinion, 'contract', $id);
        $this->publish(LinCrmEvent::CONTRACT_AUDIT_RESULT, ['id' => $id, 'result' => $approve ? 'approved' : 'rejected']);
        return $this->ok(null, '审批完成');
    }

    /** 合同变更（FR-CON-07：生效后改额/延期追加留痕；已回款期次禁改；BND-06 行锁） */
    public function change(Request $req)
    {
        $this->checkPerm('lin-crm:contract:change');
        $id = (int) $req->param('id');
        $field = (string) $req->param('field');   // amount / effective_to
        $value = (string) $req->param('value');
        $reason = (string) $req->param('reason');
        if (!in_array($field, ['amount', 'effective_to'], true)) { return $this->fail('定义缺失：变更字段不合法'); }
        if (!$reason) { return $this->fail('变更原因为必填项'); }
        Db::startTrans();
        try {
            $row = Db::name('lg_lin-crm_contract')->whereNull('deleted_at')->where('id', $id)->lock(true)->find();
            if (!$row || $row['status'] !== 'effective') { Db::rollback(); return $this->fail('仅生效合同可变更'); }
            if ($field === 'amount') {
                $paidPlans = Db::name('lg_lin-crm_receivable_plan')->where('contract_id', $id)->where('received_amount', '>', 0)->count();
                if ($paidPlans && (float) $value < (float) $row['paid_amount']) {
                    Db::rollback(); return $this->fail('变更后合同额不可低于已回款额（已回款期次禁改）');
                }
                Db::name('lg_lin-crm_contract')->where('id', $id)->update([
                    'amount' => (float) $value,
                    'balance' => round((float) $value - (float) $row['paid_amount'], 2),
                    'updated_at' => now_fmt(),
                ]);
            } else {
                Db::name('lg_lin-crm_contract')->where('id', $id)->update(['effective_to' => $value, 'updated_at' => now_fmt()]);
            }
            Db::name('lg_lin-crm_contract_change')->insert([
                'contract_id' => $id, 'change_type' => $field,
                'old_value' => (string) $row[$field], 'new_value' => $value,
                'reason' => $reason, 'operator_id' => $this->currentUserId(),
            ]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        return $this->ok(null, '变更已留痕');
    }

    /** 回款计划维护（FR-CON-03：仅 effective；期次唯一；金额精度 DECIMAL(12,2)） */
    public function planSave(Request $req)
    {
        $this->checkPerm('lin-crm:receivable:plan');
        $contractId = (int) $req->param('contract_id');
        $plans = (array) $req->param('plans', []); // [{period_no,amount,plan_date}]
        $contract = $this->m->q()->where('id', $contractId)->find();
        if (!$contract || $contract['status'] !== 'effective') { return $this->fail('仅生效合同可维护回款计划'); }
        Db::startTrans();
        try {
            foreach ($plans as $p) {
                $periodNo = (int) ($p['period_no'] ?? 0);
                if ($periodNo < 1) { Db::rollback(); return $this->fail('期次号必须从 1 起'); }
                $exists = Db::name('lg_lin-crm_receivable_plan')
                    ->where('contract_id', $contractId)->where('period_no', $periodNo)->find();
                $row = ['period_no' => $periodNo, 'amount' => round((float) $p['amount'], 2), 'plan_date' => (string) $p['plan_date']];
                if ($exists) {
                    if ((float) $exists['received_amount'] > 0) { continue; } // 已有回款期次禁改（FR-CON-07）
                    Db::name('lg_lin-crm_receivable_plan')->where('id', $exists['id'])->update($row + ['updated_at' => now_fmt()]);
                } else {
                    Db::name('lg_lin-crm_receivable_plan')->insert($row + ['contract_id' => $contractId]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        return $this->ok(null, '回款计划已保存');
    }

    /** 回款登记（FR-CON-04：部分收款；BND-03 超收拦截；trade_no 幂等；三数联动；发 EVT-PUB-06） */
    public function receivable(Request $req)
    {
        $this->checkPerm('lin-crm:receivable:register');
        $planId = (int) $req->param('plan_id');
        $amount = round((float) $req->param('amount'), 2);
        $tradeNo = (string) $req->param('trade_no');
        if ($amount <= 0) { return $this->fail('回款金额必须大于零'); }
        if ($tradeNo && Db::name('lg_lin-crm_receivable_record')->where('trade_no', $tradeNo)->find()) {
            return $this->ok(null, '幂等命中：该流水号已登记'); // RL-5/BND-08 幂等
        }
        Db::startTrans();
        try {
            $plan = Db::name('lg_lin-crm_receivable_plan')->where('id', $planId)->lock(true)->find();
            if (!$plan) { Db::rollback(); return $this->fail('期次不存在'); }
            $unreceived = round((float) $plan['amount'] - (float) $plan['received_amount'], 2);
            if ($amount > $unreceived + 0.001) {
                Db::rollback(); return $this->fail('超出期次未收余额 ' . $unreceived . '（BND-03 拦截）');
            }
            Db::name('lg_lin-crm_receivable_record')->insert([
                'plan_id' => $planId, 'contract_id' => (int) $plan['contract_id'],
                'amount' => $amount, 'paid_at' => (string) $req->param('paid_at', date('Y-m-d')),
                'trade_no' => $tradeNo, 'operator_id' => $this->currentUserId(),
            ]);
            $received = round((float) $plan['received_amount'] + $amount, 2);
            Db::name('lg_lin-crm_receivable_plan')->where('id', $planId)->update([
                'received_amount' => $received,
                'status' => $received >= (float) $plan['amount'] ? 'settled' : 'partial',
                'updated_at' => now_fmt(),
            ]);
            // 合同三数联动：合同额/已回款/应收余额（FR-CON-04）
            $contract = Db::name('lg_lin-crm_contract')->where('id', $plan['contract_id'])->lock(true)->find();
            $paid = round((float) $contract['paid_amount'] + $amount, 2);
            $settledAll = !Db::name('lg_lin-crm_receivable_plan')->where('contract_id', $plan['contract_id'])
                ->where('received_amount', '<', Db::raw('amount'))->count();
            Db::name('lg_lin-crm_contract')->where('id', $plan['contract_id'])->update([
                'paid_amount' => $paid, 'balance' => round((float) $contract['amount'] - $paid, 2),
                'status' => ($settledAll && $contract['status'] === 'effective') ? 'settled' : $contract['status'],
                'updated_at' => now_fmt(),
            ]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        $this->publish(LinCrmEvent::RECEIVABLE_PAID, ['contract_id' => $plan['contract_id'], 'plan_id' => $planId, 'amount' => $amount]);
        return $this->ok(null, '回款已登记');
    }

    /** 定时任务：回款到期提醒（FR-CON-05 三档 T-3/T-1/D+1；幂等：当日同档不重复） */
    public static function cronReceivableRemind(): array
    {
        $offsets = array_map('trim', explode(',', (string) (config('crm.receivable.remind_offsets') ?? 'T-3,T-1,D+1')));
        $sent = 0;
        foreach ($offsets as $off) {
            $days = (int) filter_var($off, FILTER_SANITIZE_NUMBER_INT); // 3 / 1 / -1(D+1 逾期)
            $op = str_starts_with($off, 'D') ? '<' : '<=';               // D+n 逾期判定
            $abs = abs($days);
            $rows = Db::name('lg_lin-crm_receivable_plan')->alias('p')
                ->join('`lg_lin-crm_contract` c', 'c.id = p.contract_id AND c.deleted_at IS NULL')
                ->whereNull('p.updated_at')->where('p.status', '<>', 'settled')
                ->whereRaw("DATEDIFF(p.plan_date, NOW()) " . ($days < 0 ? '< 0' : "{$op} {$abs}"))
                ->field('p.id,p.contract_id,p.plan_date,c.owner_id,c.name')
                ->select()->toArray();
            foreach ($rows as $r) {
                $dup = Db::name('lg_lin-crm_notify_log')->where('biz_type', 'receivable')->where('biz_id', $r['id'])
                    ->whereLike('title', '%回款提醒%')->whereTime('created_at', 'today')->count();
                if ($dup) { continue; }
                foreach (array_unique([(int) $r['owner_id']]) as $uid) {
                    Db::name('lg_lin-crm_notify_log')->insert([
                        'user_id' => $uid, 'title' => '回款提醒（' . $off . '）',
                        'content' => '合同「' . $r['name'] . '」期次 #' . $r['id'] . ' 计划收款日 ' . $r['plan_date'],
                        'biz_type' => 'receivable', 'biz_id' => $r['id'],
                    ]);
                }
                $sent++;
            }
        }
        return ['reminded' => $sent];
    }
}
