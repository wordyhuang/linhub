<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\InventoryService;
use Lg\Pack\Trade\Service\ReconciliationService;
use Lg\Pack\Trade\Service\ReportService;
use Lg\Pack\Trade\model\Customer;
use Lg\Pack\Trade\model\Supplier;
use Lg\Pack\Trade\model\Reconciliation;
use think\facade\Request;
use think\Response;

/**
 * 报表统计控制器。
 *
 * 权限点：trade:report:view
 */
class Report extends TradeController
{
    public function __construct(
        private ReportService $report,
        private InventoryService $inventory,
        private ReconciliationService $reconciliation
    ) {
    }

    public function index(): Response
    {
        return $this->render('report/index');
    }

    /** GET /trade/report/overview —— 库存总览 + 出入库趋势 */
    public function overview(): Response
    {
        $cond = [
            'start' => (string) $this->arg('start', ''),
            'end'   => (string) $this->arg('end', ''),
        ];
        return $this->jsonOk([
            'overview' => $this->report->stockOverview(),
            'trend'    => $this->report->inventoryTrend($cond),
        ]);
    }

    // ---------------- 销售排行 / 进销存汇总（V_1.1，PRD 3.5） ----------------

    /** GET /trade/report/salesRank —— 销售排行独立页 */
    public function salesRank(): Response
    {
        return $this->render('report/salesRank');
    }

    /** GET /trade/report/salesRankRows —— 商品销售排行（分页，按销售金额降序） */
    public function salesRankRows(): Response
    {
        $args = $this->pageArgs();
        $cond = [
            'start' => (string) $this->arg('start', ''),
            'end'   => (string) $this->arg('end', ''),
        ];
        $limit = max(1, $args['page']) * max(1, $args['pageSize']);
        $rows  = $this->report->saleRanking($cond, $limit);
        $list  = array_slice($rows, (max(1, $args['page']) - 1) * max(1, $args['pageSize']), max(1, $args['pageSize']));
        return $this->jsonOk(['total' => count($rows), 'list' => array_values($list)]);
    }

    /** GET /trade/report/summary —— 进销存汇总独立页 */
    public function summary(): Response
    {
        return $this->render('report/summary');
    }

    /** GET /trade/report/summaryRows —— 进销存汇总分页（商品/期初/采购/销售/期末/销额/成本/毛利） */
    public function summaryRows(): Response
    {
        $args = $this->pageArgs();
        $cond = [
            'start' => (string) $this->arg('start', ''),
            'end'   => (string) $this->arg('end', ''),
        ];
        $data = $this->report->stockSummary($cond, $args['page'], $args['pageSize']);
        return $this->jsonOk($data);
    }

    /** GET /trade/report/export —— 导出报表 CSV（summary 进销存汇总 / salesRank 销售排行） */
    public function export(): Response
    {
        $type = (string) $this->arg('type', 'summary');
        $cond = [
            'start' => (string) $this->arg('start', ''),
            'end'   => (string) $this->arg('end', ''),
        ];
        if ('salesRank' === $type) {
            $rows   = $this->report->saleRanking($cond, 100000);
            $header = [
                'product_no' => '商品编码', 'product_name' => '商品名称', 'spec' => '规格',
                'qty' => '数量', 'amount' => '销售金额', 'cost_price' => '成本', 'profit' => '毛利',
            ];
            return $this->csvResponse($rows, $header, '销售排行');
        }
        $res  = $this->report->stockSummary($cond, 1, 100000);
        $rows = $res['list'];
        $header = [
            'product_no' => '商品编码', 'name' => '商品名称',
            'open_qty' => '期初库存', 'buy_qty' => '本期采购', 'sell_qty' => '本期销售', 'end_qty' => '期末库存',
            'amount' => '销售金额', 'cost' => '成本', 'profit' => '毛利',
        ];
        return $this->csvResponse($rows, $header, '进销存汇总');
    }

    // ---------------- 对账单（V_1.1，PRD 3.5） ----------------

    /** GET /trade/report/reconciliation —— 对账单页 */
    public function reconciliation(): Response
    {
        return $this->render('report/reconciliation');
    }

    /** GET /trade/report/reconciliationList —— 对账单列表（按类型/目标/期间） */
    public function reconciliationList(): Response
    {
        $query = Reconciliation::order('id', 'desc');
        $biz   = (string) $this->arg('biz_type', '');
        if ('' !== $biz) {
            $query->where('biz_type', $biz);
        }
        $target = (int) $this->arg('target_id', 0);
        if ($target) {
            $query->where('target_id', $target);
        }
        $status = (string) $this->arg('status', '');
        if ('' !== $status) {
            $query->where('status', $status);
        }
        $list = $query->limit((int) $this->arg('limit', 100))->select()->toArray();
        // 附带目标名称
        foreach ($list as &$row) {
            if ('customer' === $row['biz_type']) {
                $row['target_name'] = (string) (Customer::find($row['target_id'])->name ?? '');
            } else {
                $row['target_name'] = (string) (Supplier::find($row['target_id'])->name ?? '');
            }
        }
        unset($row);
        return $this->jsonOk($this->withStatusLabels($list, 'reconciliation'));
    }

    /** GET /trade/report/reconciliationDetail —— 对账单详情（含快照） */
    public function reconciliationDetail(): Response
    {
        $data = $this->reconciliation->detail((int) $this->arg('id', 0));
        if ($data) {
            $data['status_label'] = $this->labels()->status('reconciliation', $data['status'] ?? null);
        }
        return $data ? $this->jsonOk($data) : $this->jsonFail('对账单不存在');
    }

    /** POST /trade/report/reconciliationGenerate —— 生成对账单 */
    public function reconciliationGenerate(): Response
    {
        try {
            $data = $this->reconciliation->generate(
                (string) Request::post('biz_type', 'customer'),
                (int) Request::post('target_id', 0),
                (string) Request::post('period_start', ''),
                (string) Request::post('period_end', ''),
                $this->uid()
            );
            return $this->jsonOk($data, '对账单已生成');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/report/reconciliationAction —— 确认/发送/作废统一入口 */
    public function reconciliationAction(): Response
    {
        try {
            $id     = (int) Request::post('id', 0);
            $action = (string) Request::post('action', 'confirm');
            if ('confirm' === $action) {
                $this->reconciliation->confirm($id, $this->uid());
            } elseif ('send' === $action) {
                $this->reconciliation->markSent($id, $this->uid());
            } elseif ('void' === $action) {
                $this->reconciliation->void($id, $this->uid(), (string) Request::post('reason', '') ?: null);
            } else {
                return $this->jsonFail('不支持的动作：' . $action);
            }
            return $this->jsonOk(null, '操作成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/report/targets —— 客户/供应商下拉（对账单用） */
    public function targets(): Response
    {
        $biz    = (string) $this->arg('biz_type', 'customer');
        $list   = 'customer' === $biz
            ? Customer::order('id', 'asc')->field('id, name')->select()->toArray()
            : Supplier::order('id', 'asc')->field('id, name')->select()->toArray();
        return $this->jsonOk($list);
    }
}