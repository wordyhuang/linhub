<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use Lg\Pack\LinCrm\LinCrmEvent;
use Lg\Pack\LinCrm\Model\Lead;
use Lg\Pack\LinCrm\Service\CodeService;
use think\facade\Db;
use think\Request;

/**
 * 销售线索跟进（FR-LEA-01~06，M1 主线）
 */
class LeadController extends LinCrmBaseController
{
    private Lead $m;

    public function __construct() { $this->m = new Lead(); }

    /** 线索管理页/线索池页（GUI，菜单入口；同页复用，视图按路径自动切线索池视角） */
    public function pageLead()
    {
        $this->checkPerm('lin-crm:lead:view');
        return $this->render('lead/index');
    }

    /** 线索列表 JSON（FR-LEA-01/03/06；pool=1 即线索池视图） */
    public function rows(Request $req)
    {
        $this->checkPerm('lin-crm:lead:view');
        $q = $this->m->q();
        if ($this->arg('pool')) {
            $q->whereIn('status', ['unassigned', 'recycled']); // 线索池视图
        } else {
            $this->scope($q);
        }
        foreach (['status', 'source', 'intent_level'] as $f) {
            if ($v = $this->arg($f)) { $q->where($f, $v); }
        }
        if ($kw = trim((string) $this->arg('keyword', ''))) {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('name', '%' . $kw . '%')->whereOr('phone', 'like', $kw . '%');
            });
        }
        return $this->ok($this->pageShape($q->order('updated_at', 'desc')->paginate((int) ($this->arg('page_size', 20)))));
    }

    /** 批量分配（GUI 线索池多选；to_user_id=0 时轮询给持有最少者，成功数回执） */
    public function batch(Request $req)
    {
        $this->checkPerm('lin-crm:lead:assign');
        $ids = array_values(array_filter(array_map('intval', (array) $req->param('ids', []))));
        if (!$ids) { return $this->fail('请先勾选线索'); }
        $to = (int) $req->param('to_user_id', 0);
        if (!$to) { $to = $this->pickByRoundRobin(); }
        $done = 0;
        foreach ($ids as $id) {
            $hit = $this->m->q()->where('id', $id)->whereIn('status', ['unassigned', 'recycled'])->update([
                'owner_id' => $to, 'status' => 'assigned', 'updated_at' => now_fmt(),
            ]);
            if ($hit) { $done++; }
        }
        if ($done) {
            $this->notify($to, '批量线索分配', "已分配 {$done} 条线索给您", 'lead', 0);
            $this->audit('assign', 'lead', 0, 'batch:' . implode(',', $ids));
        }
        return $this->ok(['done' => $done, 'to_user_id' => $to], "成功分配 {$done} / " . count($ids) . ' 条');
    }

    /** 线索时间线（GUI 详情抽屉：跟进记录 + 回收留痕，倒序 100 条） */
    public function timeline(Request $req)
    {
        $this->checkPerm('lin-crm:lead:view');
        $id = (int) $req->param('id');
        $events = [];
        $methods = ['phone' => '电话', 'visit' => '拜访', 'wechat' => '微信', 'email' => '邮件', 'other' => '其他'];
        foreach (Db::name('lg_lin-crm_follow_up')->where('biz_type', 'lead')->where('biz_id', $id)
                     ->order('created_at', 'desc')->limit(60)->select() as $f) {
            $events[] = ['time' => (string) $f['created_at'], 'type' => 'followup',
                         'title' => '跟进 · ' . ($methods[$f['method']] ?? (string) $f['method']), 'detail' => (string) $f['content']];
        }
        $lead = $this->m->q()->where('id', $id)->find();
        if ($lead && !empty($lead['recycle_reason'])) {
            $events[] = ['time' => (string) $lead['updated_at'], 'type' => 'recycle',
                         'title' => '回收进线索池', 'detail' => '原因：' . $lead['recycle_reason']];
        }
        usort($events, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));
        return $this->ok(['list' => array_slice($events, 0, 100), 'lead' => $lead]);
    }

    /** 录入（FR-LEA-01/02：来源渠道必填；GR-4 手机号撞线索/撞客户双拦截） */
    public function save(Request $req)
    {
        $this->checkPerm('lin-crm:lead:create');
        $name = trim((string) $req->param('name'));
        $phone = trim((string) $req->param('phone'));
        $source = (string) $req->param('source');
        if (!$name || !$phone || !$source) { return $this->fail('名称/手机号/来源渠道为必填项'); }

        // 撞客户（跨表查重，引导认领/查看）
        if ($dupC = Db::name('lg_lin-crm_customer')->whereNull('deleted_at')->where('phone', $phone)->find()) {
            return json(['code' => 40901, 'message' => '撞单：该手机号已是客户', 'data' => $dupC]);
        }
        // 撞线索
        if ($dupL = $this->m->q()->where('phone', $phone)->find()) {
            return json(['code' => 40902, 'message' => '撞单：线索已存在（去认领/查看）', 'data' => $dupL]);
        }

        $id = (int) Db::name('lg_lin-crm_lead')->insertGetId([
            'code' => CodeService::next('lead'), 'name' => $name, 'phone' => $phone,
            'source' => $source, 'intent_level' => (string) $req->param('intent_level', 'mid'),
            'status' => 'unassigned', 'updated_at' => now_fmt(),
        ]);
        return $this->ok(['id' => $id]);
    }

    /** 分配/认领（FR-LEA-03：手动分配+轮询分配；分配双通知） */
    public function assign(Request $req)
    {
        $this->checkPerm('lin-crm:lead:assign');
        $id = (int) $req->param('id');
        $to = (int) $req->param('to_user_id', 0);
        if (!$to) { $to = $this->pickByRoundRobin(); } // 轮询分配：私海最少者
        $hit = $this->m->q()->where('id', $id)->whereIn('status', ['unassigned', 'recycled'])->update([
            'owner_id' => $to, 'status' => 'assigned', 'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('线索状态已变更，请刷新'); }
        $this->notify($to, '新线索分配', '线索 #' . $id . ' 已分配给您', 'lead', $id);
        return $this->ok(['to_user_id' => $to]);
    }

    /** 跟进（FR-LEA-04：意向分级 + 多态跟进落表；状态 following）。content 非空时同请求落跟进明细（单请求闭环） */
    public function follow(Request $req)
    {
        $this->checkPerm('lin-crm:followup:create');
        $id = (int) $req->param('id');
        $hit = $this->m->q()->where('id', $id)->whereIn('status', ['assigned', 'following'])->update([
            'status' => 'following',
            'intent_level' => (string) $req->param('intent_level', 'mid'),
            'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('线索状态已变更，请刷新'); }
        // 跟进内容可选：填写时同请求落多态跟进明细，避免前端双请求时序问题
        $content = trim((string) $req->param('content', ''));
        if ($content !== '') {
            Db::name('lg_lin-crm_follow_up')->insert([
                'biz_type' => 'lead', 'biz_id' => $id,
                'method' => (string) $req->param('method', 'phone'),
                'content' => $content,
                'next_at' => $req->param('next_at') ?: null,
                'user_id' => $this->currentUserId(),
            ]);
        }
        return $this->ok(null, '跟进已记录');
    }

    /** 转化（FR-LEA-05：事务内建客户+联系人+可选商机；OI-007 双路径幂等；发 EVT-PUB-02） */
    public function convert(Request $req)
    {
        $this->checkPerm('lin-crm:lead:convert');
        $id = (int) $req->param('id');
        $lead = $this->m->q()->where('id', $id)->whereIn('status', ['assigned', 'following'])->find();
        if (!$lead) { return $this->fail('线索不存在或状态不可转化'); }

        Db::startTrans();
        try {
            // 行锁锁线索（OI-007：与并发新建同主体互斥，撞单由唯一索引兜底）
            $locked = Db::name('lg_lin-crm_lead')->whereNull('deleted_at')->where('id', $id)->lock(true)->find();
            $dup = Db::name('lg_lin-crm_customer')->whereNull('deleted_at')
                ->where(function ($w) use ($locked) {
                    $w->where('name', preg_replace('/\s+/u', '', $locked['name']))->whereOr('phone', $locked['phone']);
                })->find();
            if ($dup) { Db::rollback(); return json(['code' => 40901, 'message' => '撞单：客户已存在', 'data' => $dup]); }

            $customer = new \Lg\Pack\LinCrm\Model\Customer();
            $customerId = (int) Db::name('lg_lin-crm_customer')->insertGetId([
                'code' => CodeService::next('customer'),
                'name' => preg_replace('/\s+/u', '', $locked['name']),
                'phone' => $locked['phone'],
                'source' => $locked['source'],
                'owner_id' => (int) $locked['owner_id'],
                'pool_status' => 'private_pool',
                'protect_until' => date('Y-m-d H:i:s', time() + 30 * 86400),
                'updated_at' => now_fmt(),
            ]);
            Db::name('lg_lin-crm_contact')->insert([
                'customer_id' => $customerId, 'name' => $locked['name'], 'phone' => $locked['phone'], 'is_primary' => 1,
            ]);
            $opportunityId = 0;
            if ($req->param('with_opportunity')) {
                $opportunityId = (int) Db::name('lg_lin-crm_opportunity')->insertGetId([
                    'code' => CodeService::next('opportunity'), 'customer_id' => $customerId,
                    'name' => (string) $req->param('opportunity_name', $locked['name'] . '商机'),
                    'stage' => 'initial', 'status' => 'open',
                    'amount' => (float) $req->param('opportunity_amount', 0),
                    'owner_id' => (int) $locked['owner_id'],
                    'stage_changed_at' => now_fmt(), 'updated_at' => now_fmt(),
                ]);
            }
            $this->m->q()->where('id', $id)->update([
                'status' => 'converted', 'converted_customer_id' => $customerId, 'updated_at' => now_fmt(),
            ]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }

        $payload = ['lead_id' => $id, 'customer_id' => $customerId, 'opportunity_id' => $opportunityId];
        $this->publish(LinCrmEvent::LEAD_CONVERT, $payload);
        return $this->ok($payload); // 前端按此跳转新建客户/商机（FR-LEA-05 跳转链接）
    }

    /** 回收（FR-LEA-06：标记原因回池，可再分配） */
    public function recycle(Request $req)
    {
        $this->checkPerm('lin-crm:lead:assign');
        $id = (int) $req->param('id');
        $reason = (string) $req->param('reason');
        if (!$reason) { return $this->fail('回收原因为必填项'); }
        $hit = $this->m->q()->where('id', $id)->whereIn('status', ['assigned', 'following'])->update([
            'status' => 'recycled', 'owner_id' => 0, 'recycle_reason' => $reason, 'updated_at' => now_fmt(),
        ]);
        return $hit ? $this->ok(null, '已回收进线索池') : $this->fail('线索状态已变更，请刷新');
    }

    /** 轮询分配：当前持有线索最少者（简化公平策略） */
    private function pickByRoundRobin(): int
    {
        $row = $this->m->q()->whereIn('status', ['assigned', 'following'])
            ->group('owner_id')->field('owner_id, count(*) AS c')->order('c', 'asc')->find();
        return (int) ($row['owner_id'] ?? $this->currentUserId());
    }
}
