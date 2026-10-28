<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use Lg\Pack\LinCrm\LinCrmEvent;
use Lg\Pack\LinCrm\Model\Opportunity;
use Lg\Pack\LinCrm\Service\CodeService;
use think\facade\Db;
use think\Request;

/**
 * 商机管理（FR-OPP-01~05，M2）
 */
class OpportunityController extends LinCrmBaseController
{
    private Opportunity $m;

    public function __construct() { $this->m = new Opportunity(); }

    /** 商机管理页（GUI，菜单入口） */
    public function pageIndex()
    {
        $this->checkPerm('lin-crm:opportunity:view');
        return $this->render('opportunity/index');
    }

    /** 商机列表 JSON（FR-OPP-01） */
    public function rows(Request $req)
    {
        $this->checkPerm('lin-crm:opportunity:view');
        $q = $this->m->q();
        $this->scope($q);
        foreach (['status', 'stage', 'customer_id'] as $f) {
            if ($v = $this->arg($f)) { $q->where($f, $v); }
        }
        if ($kw = trim((string) $this->arg('keyword', ''))) {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('name', '%' . $kw . '%')->whereOr('code', 'like', '%' . $kw . '%');
            });
        }
        return $this->ok($this->pageShape($q->order('updated_at', 'desc')->paginate((int) ($this->arg('page_size', 20)))));
    }

    /** 漏斗看板（FR-OPP-02/04：各阶段数量/金额/加权预测） */
    public function funnel()
    {
        $this->checkPerm('lin-crm:opportunity:view');
        $winRates = config('crm.opportunity.win_rates', [10, 30, 50, 70, 100]);
        $rows = $this->m->q()->where('status', 'open')
            ->field("stage, count(*) AS cnt, sum(amount) AS amount")->group('stage')->select()->toArray();
        $map = array_column($rows, null, 'stage');
        $funnel = [];
        foreach (Opportunity::STAGES as $i => $stage) {
            $cnt = (int) ($map[$stage]['cnt'] ?? 0);
            $amount = (float) ($map[$stage]['amount'] ?? 0);
            $rate = (float) ($winRates[$i] ?? 10);
            $funnel[] = ['stage' => $stage, 'count' => $cnt, 'amount' => $amount, 'win_rate' => $rate, 'forecast' => round($amount * $rate / 100, 2)];
        }
        $forecast = array_sum(array_column($funnel, 'forecast'));
        return $this->ok(['funnel' => $funnel, 'forecast_total' => $forecast]); // 预测口径 T-OPP-03
    }

    /** 新建/编辑（FR-OPP-01：必挂客户；参与人维护） */
    public function save(Request $req)
    {
        $this->checkPerm($req->param('id') ? 'lin-crm:opportunity:edit' : 'lin-crm:opportunity:create');
        $customerId = (int) $req->param('customer_id');
        if (!$customerId || !Db::name('lg_lin-crm_customer')->whereNull('deleted_at')->where('id', $customerId)->find()) {
            return $this->fail('商机必须挂有效客户'); // 无客户禁止建档
        }
        Db::startTrans();
        try {
            $data = [
                'customer_id' => $customerId,
                'name' => (string) $req->param('name'),
                'amount' => (float) $req->param('amount', 0),
                'expect_close' => $req->param('expect_close') ?: null,
                'owner_id' => (int) ($req->param('owner_id') ?: $this->currentUserId()),
                'updated_at' => now_fmt(),
            ];
            if ($id = (int) $req->param('id', 0)) {
                $this->m->q()->where('id', $id)->update($data);
            } else {
                $data += ['code' => CodeService::next('opportunity'), 'stage' => 'initial', 'status' => 'open',
                          'win_rate' => 10, 'stage_changed_at' => now_fmt()];
                $id = (int) Db::name('lg_lin-crm_opportunity')->insertGetId($data);
            }
            foreach ((array) $req->param('members', []) as $uid) {
                Db::name('lg_lin-crm_opportunity_member')->insertIgnore([
                    'opportunity_id' => $id, 'user_id' => (int) $uid,
                ]) ?: Db::name('lg_lin-crm_opportunity_member')
                    ->where('opportunity_id', $id)->where('user_id', (int) $uid)->find();
            }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        return $this->ok(['id' => $id]);
    }

    /** 阶段推进/回退（FR-OPP-02/03：单向推进、回退必填原因留痕、BND-05 乐观锁、发 EVT-PUB-03） */
    public function stage(Request $req)
    {
        $this->checkPerm('lin-crm:opportunity:edit');
        $id = (int) $req->param('id');
        $to = (string) $req->param('stage');
        $reason = (string) $req->param('reason');
        $version = (int) $req->param('version', -1);

        $opp = $this->m->q()->where('id', $id)->where('status', 'open')->find();
        if (!$opp) { return $this->fail('商机不存在或已落终态'); }
        if ($version >= 0 && (int) $opp['version'] !== $version) {
            return $this->fail('商机状态已被变更（BND-05），请刷新后重试', 40903);
        }
        $stages = Opportunity::STAGES;
        $fromIdx = array_search($opp['stage'], $stages, true);
        $toIdx = array_search($to, $stages, true);
        if ($toIdx === false) { return $this->fail('定义缺失：未知阶段 ' . $to); }

        // 阶段必填动作（FR-OPP-03）：proposal 前置报价附件或金额明细；回退必填原因
        if ($toIdx > $fromIdx && $to === 'proposal') {
            $hasAttachment = Db::name('lg_lin-crm_attachment')->where('biz_type', 'proposal')->where('biz_id', $id)->count();
            if (!$hasAttachment && (float) $opp['amount'] <= 0) {
                return $this->fail('进入方案报价前须上传报价附件或填写预计金额');
            }
        }
        if ($toIdx < $fromIdx && !$reason) { return $this->fail('阶段回退必须填写原因（留痕）'); }

        $winRates = config('crm.opportunity.win_rates', [10, 30, 50, 70, 100]);
        $hit = $this->m->q()->where('id', $id)->where('version', (int) $opp['version'])->where('status', 'open')->update([
            'stage' => $to,
            'win_rate' => (float) ($winRates[$toIdx] ?? 10),
            'stage_changed_at' => now_fmt(),
            'version' => (int) $opp['version'] + 1,
            'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('商机状态已被变更（BND-05），请刷新后重试', 40903); }
        if ($reason) {
            Db::name('lg_lin-crm_follow_up')->insert([ // 回退留痕挂跟进时间线
                'biz_type' => 'opportunity', 'biz_id' => $id, 'method' => 'other',
                'content' => '阶段回退：' . $opp['stage'] . ' → ' . $to . '，原因：' . $reason,
                'user_id' => $this->currentUserId(),
            ]);
        }
        $this->publish(LinCrmEvent::OPPORTUNITY_STAGE, ['id' => $id, 'from' => $opp['stage'], 'to' => $to, 'rollback' => (bool) $reason]);
        return $this->ok(null, '阶段已推进');
    }

    /** 赢单/输单（FR-OPP-02：终态必填原因；发 EVT-PUB-04；输单可重开） */
    public function result(Request $req)
    {
        $perm = $req->param('result') === 'won' ? 'lin-crm:opportunity:won' : 'lin-crm:opportunity:lost';
        $this->checkPerm($perm);
        $id = (int) $req->param('id');
        $result = (string) $req->param('result');
        $reason = (string) $req->param('reason');
        if (!$reason) { return $this->fail('落终态必须填写结果原因'); }
        if ($req->param('reopen')) { // lost 重开回 open（留痕）
            $hit = $this->m->q()->where('id', $id)->where('status', 'lost')->update([
                'status' => 'open', 'result_reason' => '', 'closed_at' => null,
                'stage' => 'initial', 'stage_changed_at' => now_fmt(), 'updated_at' => now_fmt(),
            ]);
            return $hit ? $this->ok(null, '商机已重开') : $this->fail('仅输单商机可重开');
        }
        $hit = $this->m->q()->where('id', $id)->where('status', 'open')->update([
            'status' => $result === 'won' ? 'won' : 'lost',
            'result_reason' => $reason,
            'competitor' => (string) $req->param('competitor', ''),
            'win_rate' => $result === 'won' ? 100 : 0,
            'closed_at' => now_fmt(), 'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('商机状态已变更，请刷新'); }
        $this->publish(LinCrmEvent::OPPORTUNITY_RESULT, ['id' => $id, 'result' => $result, 'reason' => $reason]);
        return $this->ok(null, $result === 'won' ? '恭喜赢单，可继续创建合同' : '已记录输单');
    }

    /** 停滞列表（FR-OPP-05：stage_changed_at 超 stagnant_days；行权限过滤） */
    public function stagnant()
    {
        $this->checkPerm('lin-crm:opportunity:view');
        $days = (int) (config('crm.opportunity.stagnant_days') ?? 14);
        $q = $this->m->q()->where('status', 'open')
            ->whereRaw("stage_changed_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        $this->scope($q);
        return $this->ok($q->order('stage_changed_at', 'asc')->select()->toArray());
    }

    /** 定时任务：停滞预警（T-OPP-04；幂等：当日已提醒不重复） */
    public static function cronStagnantAlert(): array
    {
        $days = (int) (config('crm.opportunity.stagnant_days') ?? 14);
        $rows = Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('status', 'open')
            ->whereRaw("stage_changed_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)")
            ->field('id,owner_id,name')->select()->toArray();
        foreach ($rows as $r) {
            $dup = Db::name('lg_lin-crm_notify_log')->where('biz_type', 'opportunity')->where('biz_id', $r['id'])
                ->whereLike('title', '商机停滞%')->whereTime('created_at', 'today')->count();
            if ($dup) { continue; } // 当日幂等
            Db::name('lg_lin-crm_notify_log')->insert([
                'user_id' => $r['owner_id'], 'title' => '商机停滞预警',
                'content' => '商机「' . $r['name'] . '」停滞超过 ' . $days . ' 天', 'biz_type' => 'opportunity', 'biz_id' => $r['id'],
            ]);
        }
        return ['alerted' => count($rows)];
    }
}
