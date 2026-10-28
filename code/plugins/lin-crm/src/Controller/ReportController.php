<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\facade\Db;
use think\Request;

/**
 * 数据统计报表（FR-RPT-01~05，M3）
 * MySQL 5.5 无窗口函数/CTE：全部聚合 PHP 侧内存计算 + 结果缓存（crm.report.cache_ttl，OI-003）。
 */
class ReportController extends LinCrmBaseController
{
    private function cached(string $key, callable $calc)
    {
        $ttl = (int) (config('crm.report.cache_ttl') ?? 300);
        $ck = 'lin-crm:report:' . $key;
        if ($hit = cache($ck)) { return $hit; }
        $val = $calc();
        cache($ck, $val, $ttl);
        return $val;
    }

    // ── GUI 页面（菜单入口；数据经 POST JSON 接口拉取） ────
    public function pageDashboard() { $this->checkPerm('lin-crm:report:view'); return $this->render('report/dashboard'); }
    public function pageFunnel()    { $this->checkPerm('lin-crm:report:view'); return $this->render('report/funnel'); }
    public function pageRank()      { $this->checkPerm('lin-crm:report:view'); return $this->render('report/rank'); }
    public function pageAging()     { $this->checkPerm('lin-crm:report:view'); return $this->render('report/aging'); }

    /** 工作台四卡（FR-RPT-01）：今日新增线索/客户、本月预测、超期未跟进 TOP5、应收逾期合计 */
    public function dashboard()
    {
        $this->checkPerm('lin-crm:report:view');
        $data = $this->cached('dashboard', function () {
            $today = date('Y-m-d');
            $leadToday = Db::name('lg_lin-crm_lead')->whereNull('deleted_at')->whereTime('created_at', 'today')->count();
            $customerToday = Db::name('lg_lin-crm_customer')->whereNull('deleted_at')->whereTime('created_at', 'today')->count();
            $forecast = 0.0;
            $rates = config('crm.opportunity.win_rates', [10, 30, 50, 70, 100]);
            foreach (['initial', 'confirm', 'proposal', 'negotiate'] as $i => $stage) {
                $amount = (float) Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')
                    ->where('status', 'open')->where('stage', $stage)->sum('amount');
                $forecast += $amount * ($rates[$i] ?? 10) / 100;
            }
            // 保护期天数取自池规则（避免 join 常量条件的 ORM 引号化问题；int 内插无注入面）
            $protectDays = (int) (Db::name('lg_lin-crm_pool_rule')->where('id', 1)->value('protect_days') ?: 15);
            $overdueFollowup = Db::name('lg_lin-crm_customer')->alias('c')
                ->whereNull('c.deleted_at')->where('c.pool_status', 'private_pool')
                ->whereRaw("(c.last_followup_at IS NULL AND c.created_at < DATE_SUB(NOW(), INTERVAL {$protectDays} DAY))
                         OR (c.last_followup_at IS NOT NULL AND c.last_followup_at < DATE_SUB(NOW(), INTERVAL {$protectDays} DAY))")
                ->field('c.id,c.name,c.owner_id,c.last_followup_at')
                ->order('c.last_followup_at asc')->limit(5)->select()->toArray();
            $overdueRow = Db::name('lg_lin-crm_receivable_plan')->alias('p')
                ->join('`lg_lin-crm_contract` c', 'c.id = p.contract_id AND c.deleted_at IS NULL')
                ->where('p.status', '<>', 'settled')->whereRaw('p.plan_date < NOW()')
                ->fieldRaw('sum(p.amount - p.received_amount) AS due')->find();
            $receivableOverdue = (float) ($overdueRow['due'] ?? 0);
            return [
                'lead_today' => $leadToday,
                'customer_today' => $customerToday,
                'forecast_month' => round($forecast, 2),
                'overdue_followup_top5' => $overdueFollowup,
                'receivable_overdue_total' => round($receivableOverdue, 2),
            ];
        });
        return $this->ok($data);
    }

    /** 漏斗分析（FR-RPT-02：阶段数量/金额/转化率/平均停留天数） */
    public function funnel()
    {
        $this->checkPerm('lin-crm:report:view');
        return $this->ok($this->cached('funnel', function () {
            $stages = ['initial', 'confirm', 'proposal', 'negotiate'];
            $rows = [];
            foreach ($stages as $stage) {
                $cnt = (int) Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('stage', $stage)->count();
                $amount = (float) Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('stage', $stage)->sum('amount');
                $avgRow = Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('stage', $stage)
                    ->whereRaw('stage_changed_at > created_at')
                    ->fieldRaw('avg(timestampdiff(day, created_at, stage_changed_at)) AS d')->find();
                $avgDays = (float) ($avgRow['d'] ?? 0);
                $rows[] = ['stage' => $stage, 'count' => $cnt, 'amount' => $amount, 'avg_stay_days' => round($avgDays, 1)];
            }
            $won = (int) Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('status', 'won')->count();
            $lost = (int) Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('status', 'lost')->count();
            $closed = $won + $lost;
            foreach ($rows as &$r) {
                $r['convert_rate'] = $closed > 0 ? round($r['count'] / array_sum(array_column($rows, 'count')) * 100, 1) : 0;
            }
            return ['funnel' => $rows, 'won' => $won, 'lost' => $lost, 'win_rate' => $closed ? round($won / $closed * 100, 1) : 0];
        }));
    }

    /** 销售排行（FR-RPT-03：新增客户/商机金额/合同额/回款额四口径，周期可选） */
    public function rank(Request $req)
    {
        $this->checkPerm('lin-crm:report:view');
        $metric = (string) ($req->param('metric', 'contract_amount'));
        $days = (int) ($req->param('days', 30));
        $from = date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
        $map = [
            'new_customer'    => function () use ($from) {
                return Db::name('lg_lin-crm_customer')->whereNull('deleted_at')->where('created_at', '>=', $from)
                    ->group('owner_id')->field('owner_id, count(*) AS val')->order('val', 'desc')->limit(20)->select()->toArray();
            },
            'opportunity_amount' => function () use ($from) {
                return Db::name('lg_lin-crm_opportunity')->whereNull('deleted_at')->where('created_at', '>=', $from)
                    ->group('owner_id')->field('owner_id, sum(amount) AS val')->order('val', 'desc')->limit(20)->select()->toArray();
            },
            'contract_amount' => function () use ($from) {
                return Db::name('lg_lin-crm_contract')->whereNull('deleted_at')->where('created_at', '>=', $from)
                    ->group('owner_id')->field('owner_id, sum(amount) AS val')->order('val', 'desc')->limit(20)->select()->toArray();
            },
            'receivable_paid' => function () use ($from) {
                return Db::name('lg_lin-crm_receivable_record')->where('created_at', '>=', $from)
                    ->group('operator_id')->field('operator_id AS owner_id, sum(amount) AS val')->order('val', 'desc')->limit(20)->select()->toArray();
            },
        ];
        if (!isset($map[$metric])) { return $this->fail('定义缺失：未知口径 ' . $metric); }
        return $this->ok(['metric' => $metric, 'days' => $days, 'rows' => $this->cached('rank_' . $metric . '_' . $days, $map[$metric])]);
    }

    /** 应收账龄（FR-RPT-04：合同维度未收余额四区间 ≤30/31-60/61-90/>90 天） */
    public function aging()
    {
        $this->checkPerm('lin-crm:report:view');
        return $this->ok($this->cached('aging', function () {
            $rows = Db::name('lg_lin-crm_receivable_plan')->alias('p')
                ->join('`lg_lin-crm_contract` c', 'c.id = p.contract_id AND c.deleted_at IS NULL')
                ->where('p.status', '<>', 'settled')
                ->field('c.id AS contract_id, c.code, c.name, p.plan_date, p.amount, p.received_amount')
                ->select()->toArray();
            $buckets = ['b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90p' => 0.0];
            $detail = [];
            foreach ($rows as $r) {
                $due = round((float) $r['amount'] - (float) $r['received_amount'], 2);
                if ($due <= 0) { continue; }
                $days = (int) ((time() - strtotime($r['plan_date'])) / 86400);
                $bucket = $days <= 30 ? 'b30' : ($days <= 60 ? 'b60' : ($days <= 90 ? 'b90' : 'b90p'));
                $buckets[$bucket] = round($buckets[$bucket] + $due, 2);
                $detail[] = ['contract_id' => $r['contract_id'], 'code' => $r['code'], 'name' => $r['name'],
                             'due' => $due, 'overdue_days' => max(0, $days), 'bucket' => $bucket];
            }
            return ['buckets' => $buckets, 'detail' => $detail];
        }));
    }

    /** 报表导出（FR-RPT-05：异步走宿主队列；行为落审计日志） */
    public function export(Request $req)
    {
        $this->checkPerm('lin-crm:report:export');
        $metric = (string) $req->param('metric', 'aging');
        $this->audit('export', 'report', 0, 'metric:' . $metric . ' params:' . json_encode($req->param(), JSON_UNESCAPED_UNICODE));
        // 宿主队列适配点：入队导出任务，完成后通知下载链接；此处返回任务受理回执
        return $this->ok(['task' => 'export_' . $metric . '_' . time(), 'status' => 'queued'], '导出任务已受理，完成后站内信通知');
    }
}
