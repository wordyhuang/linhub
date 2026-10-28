<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\CustomerCreditService;
use Lg\Pack\Trade\Service\SaleService;
use Lg\Pack\Trade\model\Customer;
use Lg\Pack\Trade\model\CustomerCredit;
use Lg\Pack\Trade\model\SaleOrder;
use think\facade\Db;
use think\facade\Request;
use think\Response;

/**
 * 销售管理控制器。
 *
 * 权限点：trade:sale:create / trade:sale:outbound / trade:sale:return
 */
class Sale extends TradeController
{
    public function __construct(
        private SaleService $service,
        private CustomerCreditService $credit
    ) {
    }

    public function index(): Response
    {
        return $this->render('sale/index');
    }

    /** GET /trade/sale/rows —— 销售订单分页 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $cond = [
            'status'   => $args['status'],
            'order_no' => $args['keyword'],
        ];
        $data = $this->service->page($cond, $args['page'], $args['pageSize']);
        $data['list'] = $this->withStatusLabels($data['list'], 'sale_order');
        return $this->jsonOk($data);
    }

    /** GET /trade/sale/export —— 按当前筛选导出销售订单（CSV，全量不分页） */
    public function export(): Response
    {
        $args = $this->pageArgs();
        $data = $this->service->page([
            'status'   => $args['status'],
            'order_no' => $args['keyword'],
        ], 1, 100000);
        $rows = $this->withStatusLabels($data['list'], 'sale_order');

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'order_no'      => $row['order_no'],
                'customer_name' => $row['customer_name'] ?: ($row['customer_id'] ?: ''),
                'created_at'    => $row['created_at'],
                'total_amount'  => $row['total_amount'],
                'outbound_no'   => $row['outbound_no'],
                'status'        => $row['status_label'],
                'created_by'    => $row['created_by'],
            ];
        }
        $header = [
            'order_no'      => '订单号',
            'customer_name' => '客户',
            'created_at'    => '日期',
            'total_amount'  => '总额',
            'outbound_no'   => '已出库行数',
            'status'        => '状态',
            'created_by'    => '创建人',
        ];
        return $this->csvResponse($out, $header, '销售订单');
    }

    /** GET /trade/sale/detail —— 销售单明细（含明细行） */
    public function detail(): Response
    {
        $data = $this->service->detail((int) Request::param('id', 0));
        if ($data) {
            $data['status_label'] = $this->labels()->status('sale_order', $data['status'] ?? null);
        }
        return $data ? $this->jsonOk($data) : $this->jsonFail('销售订单不存在');
    }

    /** POST /trade/sale/save —— 新建草稿 */
    public function save(): Response
    {
        try {
            $req = json_decode(Request::post('data', '[]'), true) ?: Request::post();
            $order = $this->service->create($req, $this->uid());
            return $this->jsonOk(['id' => $order->id, 'order_no' => $order->order_no], '创建成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/outbound —— 提交并出库（扣减库存，负库存红线） */
    public function outbound(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $rows = json_decode(Request::post('rows', '[]'), true) ?: [];
            $order = SaleOrder::find($id);
            if ($order && 'draft' === (string) $order->status) {
                $this->service->submit($id);
            }
            $this->service->outbound($id, $rows, $this->operator());
            return $this->jsonOk(null, '出库成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/return —— 销售退货（回补库存，TYPE_RETURN_IN） */
    public function ret(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $rows = json_decode(Request::post('rows', '[]'), true) ?: [];
            $returnId = $this->service->return($id, $rows, $this->operator());
            return $this->jsonOk(['return_id' => $returnId], '退货成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/cancel —— 作废 */
    public function cancel(): Response
    {
        try {
            $id     = (int) Request::post('id', 0);
            $reason = (string) Request::post('reason', '作废');
            $this->service->void($id, $reason);
            return $this->jsonOk(null, '作废成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/batchCancel —— 批量作废（逐单处理，失败不影响其余单据） */
    public function batchCancel(): Response
    {
        $ids = json_decode(Request::post('ids', '[]'), true) ?: [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) {
            return $this->jsonFail('未选择要作废的订单');
        }
        $ok = [];
        $fail = [];
        foreach ($ids as $id) {
            try {
                $this->service->void($id, '批量作废');
                $ok[] = $id;
            } catch (\Throwable $e) {
                $fail[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return $this->jsonOk(
            ['ok' => $ok, 'fail' => $fail],
            '已作废 ' . count($ok) . ' 单' . ($fail ? '，失败 ' . count($fail) . ' 单' : '')
        );
    }

    // ---------------- 客户信用（V_1.1，PRD 3.9） ----------------

    /** GET /trade/sale/credit —— 客户信用页 */
    public function credit(): Response
    {
        return $this->render('sale/credit');
    }

    /** GET /trade/sale/creditRows —— 客户信用列表（额度/占用/可用/控制状态） */
    public function creditRows(): Response
    {
        $list = CustomerCredit::with(['customer'])
            ->order('id', 'desc')
            ->select()->toArray();
        foreach ($list as &$row) {
            $customerId       = (int) $row['customer_id'];
            $occupied         = $this->credit->occupancy($customerId);
            $limit            = (float) $row['credit_limit'];
            $row['occupancy'] = $occupied;
            $row['available'] = round($limit - $occupied, 4);
        }
        unset($row);
        return $this->jsonOk($list);
    }

    /** POST /trade/sale/credit/setLimit —— 调整客户信用额度 */
    public function creditSetLimit(): Response
    {
        try {
            $customerId = (int) Request::post('customer_id', 0);
            $newLimit   = (float) Request::post('credit_limit', 0);
            $this->credit->setLimit($customerId, $newLimit, $this->uid());
            return $this->jsonOk(null, '额度已更新');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/credit/settle —— 手工标记销售单已结清（抵减占用） */
    public function creditSettle(): Response
    {
        try {
            $this->credit->manualSettle((int) Request::post('sale_order_id', 0), $this->uid());
            return $this->jsonOk(null, '已标记结清');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/sale/creditCheck —— 出库前信用预检（在线演示解析） */
    public function creditCheck(): Response
    {
        try {
            $res = $this->credit->checkOutbound(
                (int) Request::post('customer_id', 0),
                (float) Request::post('delta_amount', 0)
            );
            return $this->jsonOk(['result' => $res]);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/sale/outstandingRows —— 销售订单未结清清单（分页，含客户/订单号/金额/已结/未结） */
    public function outstandingRows(): Response
    {
        $args    = $this->pageArgs();
        $base = function () {
            $q = Db::name('lg_lin-trade_sale_order_item')->alias('si')
                ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
                ->where('so.credit_settled', 0)
                ->where('so.customer_id', '<>', 0)
                ->where('si.outbound_qty', '>', 0);
            $keyword = $this->arg('keyword', '');
            if ('' !== $keyword) {
                $q->whereLike('so.order_no', "%{$keyword}%");
            }
            return $q;
        };

        $total = (int) $base()->group('si.order_id')->count();
        $list  = $base()
            ->join('lg_lin-trade_customer c', 'c.id = so.customer_id', 'left')
            ->field('so.id as order_id, so.order_no, so.customer_id, c.name as customer_name,
                     SUM(si.outbound_qty * si.price) as amount')
            ->group('so.id, so.order_no, so.customer_id, c.name')
            ->order('so.id', 'desc')
            ->page($args['page'], $args['pageSize'])
            ->select()->toArray();
        foreach ($list as &$row) {
            $row['amount']     = round((float) $row['amount'], 2);
            $row['settled']    = 0.0;
            $row['unsettled']  = $row['amount'];
        }
        unset($row);
        return $this->jsonOk(['total' => $total, 'list' => $list]);
    }

    /** POST /trade/sale/audit —— 销售订单审核（审核流开启时，trade:sale:audit 权限） */
    public function audit(): Response
    {
        try {
            $id      = (int) Request::post('id', 0);
            $approve = (int) Request::post('approve', 1) === 1;
            $reason  = (string) Request::post('reason', '');
            $this->service->audit($id, $approve, $this->uid(), $reason);
            return $this->jsonOk(null, $approve ? '审核通过' : '已驳回，订单回到草稿');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/sale/customers —— 客户下拉（信用页用） */
    public function customers(): Response
    {
        $list = Customer::order('id', 'asc')->field('id, name')->select()->toArray();
        return $this->jsonOk($list);
    }

    /** GET /trade/sale/returnDetail —— 销售退货单详情（打印用，PRD 3.6） */
    public function returnDetail(): Response
    {
        $data = app(\Lg\Pack\Trade\Service\SaleReturnService::class)
            ->detail((int) $this->arg('id', 0));
        if (!$data) {
            return $this->jsonFail('销售退货单不存在');
        }
        $data['status_label'] = $this->labels()->status('sale_return', $data['status'] ?? null);
        if (isset($data['check_result']) && $data['check_result'] !== null && $data['check_result'] !== '') {
            $data['check_result_label'] = $this->labels()->field('sale_return', 'check_result', $data['check_result']);
        }
        return $this->jsonOk($data);
    }
}