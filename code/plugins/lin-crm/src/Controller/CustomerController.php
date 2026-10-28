<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use Lg\Pack\LinCrm\LinCrmEvent;
use Lg\Pack\LinCrm\Model\Customer;
use Lg\Pack\LinCrm\Service\CodeService;
use think\facade\Db;
use think\Request;

/**
 * 客户信息管理（FR-CUS-01~05，M1 主线）
 */
class CustomerController extends LinCrmBaseController
{
    private Customer $m;

    public function __construct() { $this->m = new Customer(); }

    /** 客户管理页（GUI，菜单入口） */
    public function pageIndex()
    {
        $this->checkPerm('lin-crm:customer:view');
        return $this->render('customer/index');
    }

    /** 公海客户页（GUI，菜单入口；同页复用，视图按路径自动切公海视角） */
    public function pagePool()
    {
        $this->checkPerm('lin-crm:customer:view');
        return $this->render('customer/index');
    }

    /** 合并向导页（GUI，菜单入口） */
    public function pageMerge()
    {
        $this->checkPerm('lin-crm:customer:edit');
        return $this->render('customer/merge');
    }

    /** 客户列表 JSON（FR-CUS-01；GR-5 默认更新时间倒序） */
    public function rows(Request $req)
    {
        $this->checkPerm('lin-crm:customer:view');
        $q = $this->m->q();
        $this->scope($q);
        if ($kw = trim((string) $this->arg('keyword', ''))) {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('name', '%' . $kw . '%')->whereOr('phone', 'like', $kw . '%');
            });
        }
        foreach (['pool_status', 'level', 'source'] as $f) {
            if ($v = $this->arg($f)) { $q->where($f, $v); }
        }
        // 待跟进筛选（GUI 提醒直达）：存在到期未处理的下次跟进计划
        if ($this->arg('due')) {
            $q->whereRaw("EXISTS (SELECT 1 FROM `lg_lin-crm_follow_up` f WHERE f.biz_type = 'customer'"
                . ' AND f.biz_id = `lg_lin-crm_customer`.id AND f.next_at IS NOT NULL AND f.next_at <= NOW())');
        }
        $rows = $q->order('updated_at', 'desc')->paginate((int) ($this->arg('page_size', 20)));
        $mask = config('crm.phone.mask_enabled', true);
        // 注意：不能对 Paginator 直接按引用遍历（迭代器产出的是副本，修改会丢失），
        // 必须先转成普通数组再加工，否则手机号脱敏与 protect_left 均不会生效。
        $page = $this->pageShape($rows);
        foreach ($page['list'] as &$r) {
            if ($mask && !$this->canViewFullPhone()) { $r['phone'] = $this->maskPhone($r['phone']); }
            // 保护期剩余天数（GUI 倒计时标签；公海/无保护期为 null）
            $r['protect_left'] = ($r['pool_status'] === 'private_pool' && !empty($r['protect_until']))
                ? max(0, (int) ceil((strtotime((string) $r['protect_until']) - time()) / 86400)) : null;
        }
        unset($r);
        return $this->ok($page);
    }

    /** 新建/编辑（FR-CUS-01；GR-4 查重：名称+手机号命中即拦截，RL-1） */
    public function save(Request $req)
    {
        $this->checkPerm($req->param('id') ? 'lin-crm:customer:edit' : 'lin-crm:customer:create');
        $data = $req->only(['name', 'phone', 'industry', 'source', 'level', 'remark']);
        if (empty($data['name']) || empty($data['phone'])) {
            return $this->fail('名称与手机号为必填项');
        }
        $data['name'] = preg_replace('/\s+/u', '', $data['name']); // 去空格口径（GR-4）

        // 查重拦截（OI-007：新建与转化双路径统一入口）
        if ($dup = $this->findDup($data['name'], $data['phone'], (int) ($req->param('id', 0)))) {
            return $this->dupResponse($dup); // code=40901，携带既有客户引导认领/查看
        }

        Db::startTrans();
        try {
            if ($id = (int) $req->param('id', 0)) {
                $this->m->q()->where('id', $id)->update($data + ['updated_at' => now_fmt()]);
                $customerId = $id;
            } else {
                $data['code'] = CodeService::next('customer');
                $data['owner_id'] = $this->currentUserId();
                $data['pool_status'] = 'private_pool';
                $data['protect_until'] = date('Y-m-d H:i:s', time() + $this->protectDays() * 86400);
                $data['updated_at'] = now_fmt();
                $customerId = (int) Db::name('lg_lin-crm_customer')->insertGetId($data);
            }
            // 主联系人落表
            if ($contactName = (string) $req->param('contact_name', '')) {
                Db::name('lg_lin-crm_contact')->insert([
                    'customer_id' => $customerId, 'name' => $contactName,
                    'phone' => (string) $req->param('contact_phone', ''), 'is_primary' => 1,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        return $this->ok(['id' => $customerId]);
    }

    /** 详情（FR-CUS-06 数据源之一；手机号解权查看落审计） */
    public function read(Request $req)
    {
        $this->checkPerm('lin-crm:customer:view');
        $id = (int) $req->param('id');
        $row = $this->m->q()->where('id', $id)->find();
        if (!$row) { return $this->fail('客户不存在'); }
        if ($this->canViewFullPhone()) {
            $this->audit('phone_view', 'customer', $id, '查看完整手机号');
        } else {
            $row['phone'] = $this->maskPhone($row['phone']);
        }
        $row['contacts'] = Db::name('lg_lin-crm_contact')->whereNull('deleted_at')->where('customer_id', $id)->select()->toArray();
        $row['followups'] = Db::name('lg_lin-crm_follow_up')->where('biz_type', 'customer')->where('biz_id', $id)
            ->order('created_at', 'desc')->limit(50)->select()->toArray();
        return $this->ok($row);
    }

    /** 客户时间线（GUI 详情抽屉 Tab：聚合跟进/商机/合同/回款事件，倒序 100 条） */
    public function timeline(Request $req)
    {
        $this->checkPerm('lin-crm:customer:view');
        $id = (int) $req->param('id');
        $events = [];
        $methods = ['phone' => '电话', 'visit' => '拜访', 'wechat' => '微信', 'email' => '邮件', 'other' => '其他'];
        foreach (Db::name('lg_lin-crm_follow_up')->where('biz_type', 'customer')->where('biz_id', $id)
                     ->order('created_at', 'desc')->limit(60)->select() as $f) {
            $events[] = ['time' => (string) $f['created_at'], 'type' => 'followup',
                         'title' => '跟进 · ' . ($methods[$f['method']] ?? (string) $f['method']), 'detail' => (string) $f['content']];
        }
        foreach (Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('customer_id', $id)
                     ->order('created_at', 'desc')->limit(30)->select() as $o) {
            $events[] = ['time' => (string) $o['created_at'], 'type' => 'opportunity', 'title' => '商机 · ' . $o['name'],
                         'detail' => $o['code'] . '，阶段 ' . $o['stage'] . '，预计 ¥' . number_format((float) $o['amount'], 2)];
        }
        $contractIds = [];
        foreach (Db::name('lg_lin-crm_contract')->whereNull('deleted_at')->where('customer_id', $id)
                     ->order('created_at', 'desc')->limit(30)->select() as $c) {
            $contractIds[] = (int) $c['id'];
            $events[] = ['time' => (string) $c['created_at'], 'type' => 'contract', 'title' => '合同 · ' . $c['name'],
                         'detail' => $c['code'] . '，合同额 ¥' . number_format((float) $c['amount'], 2)];
        }
        if ($contractIds) {
            foreach (Db::name('lg_lin-crm_receivable_record')->whereIn('contract_id', $contractIds)
                         ->order('paid_at', 'desc')->limit(60)->select() as $r) {
                $events[] = ['time' => $r['paid_at'] . ' 00:00:00', 'type' => 'receivable',
                             'title' => '回款 · ¥' . number_format((float) $r['amount'], 2), 'detail' => '流水号 ' . ($r['trade_no'] ?: '-')];
            }
        }
        // ── 履约订单（A 方案：林销销售订单按客户名称弱关联回流；RL-5 弱依赖降级，林销未装则跳过） ──
        try {
            $cname = (string) (Db::name('lg_lin-crm_customer')->where('id', $id)->value('name') ?? '');
            if ($cname !== '') {
                $orders = Db::query(
                    'SELECT so.order_no, so.status, so.total_amount, so.updated_at'
                    . ' FROM `lg_lin-trade_sale_order` so JOIN `lg_lin-trade_customer` tc ON tc.id = so.customer_id'
                    . ' WHERE tc.name = ? ORDER BY so.updated_at DESC LIMIT 15', [$cname]);
                foreach ($orders as $o) {
                    $events[] = ['time' => (string) $o['updated_at'], 'type' => 'order',
                                 'title' => '销售订单 · ' . $o['order_no'],
                                 'detail' => (OrderController::STATUS_LABELS[$o['status']] ?? (string) $o['status'])
                                     . '，¥' . number_format((float) $o['total_amount'], 2)];
                }
            }
        } catch (\Throwable $e) {
            // 林销未安装：跳过订单回流（弱依赖降级，不阻断时间线）
        }
        usort($events, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));
        return $this->ok(['list' => array_slice($events, 0, 100)]);
    }

    /** 批量操作（GUI 多选：claim=批量认领 / pool=批量退公海 / delete=批量删除；逐条条件更新，回执成功数） */
    public function batch(Request $req)
    {
        $action = (string) $req->param('action');
        $ids = array_values(array_filter(array_map('intval', (array) $req->param('ids', []))));
        if (!$ids) { return $this->fail('请先勾选客户'); }
        $done = 0;
        switch ($action) {
            case 'claim': // 批量认领：受私海上限约束，逐条乐观抢占
                $this->checkPerm('lin-crm:customer:create');
                $uid = $this->currentUserId();
                $held = $this->m->q()->where('owner_id', $uid)->where('pool_status', 'private_pool')->count();
                $limit = (int) (config('crm.customer.private_limit') ?? 50);
                foreach ($ids as $id) {
                    if ($held >= $limit) { break; }
                    $hit = $this->m->q()->where('id', $id)->where('pool_status', 'public_pool')->update([
                        'owner_id' => $uid, 'pool_status' => 'private_pool',
                        'protect_until' => date('Y-m-d H:i:s', time() + $this->protectDays() * 86400),
                        'updated_at' => now_fmt(),
                    ]);
                    if ($hit) { $done++; $held++; }
                }
                break;
            case 'pool': // 批量退回公海（仅私海行生效；发释放事件）
                foreach ($ids as $id) {
                    $row = $this->m->q()->where('id', $id)->where('pool_status', 'private_pool')->find();
                    if (!$row) { continue; }
                    $this->m->q()->where('id', $id)->update([
                        'owner_id' => 0, 'pool_status' => 'public_pool', 'protect_until' => null, 'updated_at' => now_fmt(),
                    ]);
                    $this->publish(LinCrmEvent::CUSTOMER_RELEASE, ['customer_id' => $id, 'from_owner' => (int) $row['owner_id'], 'cause' => 'batch_giveback']);
                    $done++;
                }
                $this->audit('release', 'customer', 0, 'batch:' . implode(',', $ids));
                break;
            case 'delete': // 批量软删进回收站
                $this->checkPerm('lin-crm:customer:delete');
                foreach ($ids as $id) {
                    try {
                        $this->m->softDelete($id, $this->currentUserId());
                        $this->audit('delete', 'customer', $id, 'batch');
                        $done++;
                    } catch (\Throwable) { /* 单条失败不影响其余 */
                    }
                }
                break;
            default:
                return $this->fail('定义缺失：未知批量动作 ' . $action);
        }
        return $this->ok(['done' => $done], "成功处理 {$done} / " . count($ids) . ' 条');
    }

    /** 删除（软删进回收站，RL-3） */
    public function delete(Request $req)
    {
        $this->checkPerm('lin-crm:customer:delete');
        $this->m->softDelete((int) $req->param('id'), $this->currentUserId());
        $this->audit('delete', 'customer', (int) $req->param('id'));
        return $this->ok(null, '已移入回收站');
    }

    /** 公海认领（FR-CUS-02；受私海上限约束；BND-01 竞态以池状态条件更新兜底） */
    public function claim(Request $req)
    {
        $this->checkPerm('lin-crm:customer:create');
        $id = (int) $req->param('id');
        $uid = $this->currentUserId();
        $limit = (int) (config('crm.customer.private_limit') ?? 50);
        $held = $this->m->q()->where('owner_id', $uid)->where('pool_status', 'private_pool')->count();
        if ($held >= $limit) { return $this->fail('私海持有数已达上限 ' . $limit); }

        // 条件更新实现乐观抢占：仅 public_pool 可认领（BND-01：并发仅一人成功）
        $hit = $this->m->q()->where('id', $id)->where('pool_status', 'public_pool')->update([
            'owner_id' => $uid, 'pool_status' => 'private_pool',
            'protect_until' => date('Y-m-d H:i:s', time() + $this->protectDays() * 86400),
            'updated_at' => now_fmt(),
        ]);
        if (!$hit) { return $this->fail('已被认领', 40902); }
        return $this->ok(null, '认领成功');
    }

    /** 退回公海（FR-CUS-02；主动退回不发双通知，发释放事件） */
    public function giveback(Request $req)
    {
        $this->checkPerm('lin-crm:customer:view');
        return $this->doRelease((int) $req->param('id'), 'giveback');
    }

    /** 管理员/总监收回（FR-CUS-05 收回子项） */
    public function release(Request $req)
    {
        $this->checkPerm('lin-crm:customer:release');
        return $this->doRelease((int) $req->param('id'), 'recall');
    }

    /** 整体转移（FR-CUS-05：含商机、未完结合同；转移双通知） */
    public function transfer(Request $req)
    {
        $this->checkPerm('lin-crm:customer:transfer');
        $id = (int) $req->param('id');
        $to = (int) $req->param('to_user_id');
        $from = $this->m->q()->where('id', $id)->value('owner_id');
        Db::startTrans();
        try {
            $this->m->q()->where('id', $id)->update(['owner_id' => $to, 'updated_at' => now_fmt()]);
            Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('customer_id', $id)->update(['owner_id' => $to]);
            Db::name('lg_lin-crm_contract')->whereNull('deleted_at')->where('customer_id', $id)
                ->whereIn('status', ['draft', 'auditing', 'effective'])->update(['owner_id' => $to]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        $this->notify($from, '客户已转移', '您的客户被转移给 #' . $to, 'customer', $id);
        $this->notify($to, '客户转移接入', '客户 #' . $id . ' 已转移给您', 'customer', $id);
        $this->audit('transfer', 'customer', $id, "from:{$from} to:{$to}");
        return $this->ok(null, '转移完成');
    }

    /** 合并（FR-CUS-03：主档保留，从档软删，关联数据改挂主档，留痕） */
    public function merge(Request $req)
    {
        $this->checkPerm('lin-crm:customer:edit');
        $mainId = (int) $req->param('main_id');
        $slaveId = (int) $req->param('slave_id');
        if (!$mainId || !$slaveId || $mainId === $slaveId) { return $this->fail('合并参数不合法'); }
        Db::startTrans();
        try {
            $tables = ['lg_lin-crm_contact', 'lg_lin-crm_opportunity', 'lg_lin-crm_contract', 'lg_lin-crm_follow_up', 'lg_lin-crm_attachment'];
            foreach ($tables as $t) {
                Db::name($t)->whereNull('deleted_at')->where('customer_id', $slaveId)->update(['customer_id' => $mainId]);
            }
            Db::name('lg_lin-crm_follow_up')->where('biz_type', 'customer')->where('biz_id', $slaveId)->update(['biz_id' => $mainId]);
            $slave = new Customer();
            $slave->softDelete($slaveId, $this->currentUserId());
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); return $this->fail($e->getMessage()); }
        $this->audit('merge', 'customer', $mainId, 'slave:' . $slaveId);
        return $this->ok(null, '合并完成');
    }

    /** 新增跟进（FR-CUS-04：三对象多态；客户跟进自动续保护期；下次跟进时间落待办） */
    public function followup(Request $req)
    {
        $this->checkPerm('lin-crm:followup:create');
        $bizType = (string) $req->param('biz_type', 'customer'); // customer/lead/opportunity
        if (!in_array($bizType, ['customer', 'lead', 'opportunity'], true)) {
            return $this->fail('定义缺失：biz_type 不合法'); // 防幻觉：最保守拒绝
        }
        $bizId = (int) $req->param('biz_id');
        Db::name('lg_lin-crm_follow_up')->insert([
            'biz_type' => $bizType, 'biz_id' => $bizId,
            'method' => (string) $req->param('method', 'phone'),
            'content' => (string) $req->param('content', ''),
            'next_at' => $req->param('next_at') ?: null,
            'user_id' => $this->currentUserId(),
        ]);
        $now = now_fmt();
        if ($bizType === 'customer') {
            // RL-2 口径：更新最近跟进时间并续保护期（关键字段不可改，仅追加时间戳事实）
            $this->m->q()->where('id', $bizId)->update([
                'last_followup_at' => $now,
                'protect_until' => date('Y-m-d H:i:s', time() + $this->protectDays() * 86400),
                'updated_at' => $now,
            ]);
        }
        return $this->ok(null, '跟进已记录');
    }

    // ── 内部方法 ────────────────────────────────────────────
    private function findDup(string $name, string $phone, int $excludeId = 0): ?array
    {
        $q = $this->m->q(); $excludeId && $q->where('id', '<>', $excludeId);
        return $q->where(function ($w) use ($name, $phone) {
            $w->where('name', $name)->whereOr('phone', $phone);
        })->field('id,code,name,owner_id,pool_status')->find() ?: null;
    }

    private function dupResponse(array $dup): \think\response\Json
    {
        return json(['code' => 40901, 'message' => '撞单：客户已存在（去认领/查看）', 'data' => $dup]);
    }

    private function protectDays(): int
    {
        $rule = Db::name('lg_lin-crm_pool_rule')->where('id', 1)->find();
        return (int) ($rule['protect_days'] ?? config('crm.customer.protect_days', 30));
    }

    private function canViewFullPhone(): bool
    {
        return $this->hasPerm('lin-crm:phone:view_full');
    }

    /** 释放执行体（主动退回/管理员收回共用；发 EVT-PUB-01） */
    private function doRelease(int $id, string $cause): \think\response\Json
    {
        $row = $this->m->q()->where('id', $id)->where('pool_status', 'private_pool')->find();
        if (!$row) { return $this->fail('客户不在私海'); }
        $this->m->q()->where('id', $id)->update([
            'owner_id' => 0, 'pool_status' => 'public_pool', 'protect_until' => null, 'updated_at' => now_fmt(),
        ]);
        $this->publish(LinCrmEvent::CUSTOMER_RELEASE, ['customer_id' => $id, 'from_owner' => (int) $row['owner_id'], 'cause' => $cause]);
        $this->notify((int) $row['owner_id'], '客户已释放回公海', '客户「' . $row['name'] . '」已释放（' . $cause . '）', 'customer', $id);
        if ($cause === 'recall') { $this->audit('release', 'customer', $id); }
        return $this->ok(null, '已释放');
    }

    // ── 定时任务：公海释放（AC-CUS-02-1；幂等可重跑，T0-04 框架调度） ──
    public static function cronReleaseOverdue(): array
    {
        $m = new Customer();
        $overdue = $m->q()->where('pool_status', 'private_pool')
            ->whereRaw('(protect_until IS NOT NULL AND protect_until < NOW())')
            ->field('id,owner_id,name')->select()->toArray();
        foreach ($overdue as $row) {
            $m->q()->where('id', $row['id'])->where('pool_status', 'private_pool')->update([
                'owner_id' => 0, 'pool_status' => 'public_pool', 'protect_until' => null, 'updated_at' => now_fmt(),
            ]);
            Db::name('lg_lin-crm_notify_log')->insert([
                'user_id' => $row['owner_id'], 'title' => '客户超期释放',
                'content' => '客户「' . $row['name'] . '」超期未跟进，已自动释放回公海',
                'biz_type' => 'customer', 'biz_id' => $row['id'],
            ]);
            \think\facade\Event::trigger(LinCrmEvent::CUSTOMER_RELEASE, ['customer_id' => $row['id'], 'cause' => 'overdue']);
        }
        return ['released' => count($overdue)]; // 幂等：重复执行仅处理仍处私海且超期的行
    }
}
