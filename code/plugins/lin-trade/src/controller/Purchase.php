<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\PurchaseService;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\PurchaseOrder;
use Lg\Pack\Trade\model\PurchaseOrderItem;
use Lg\Pack\Trade\model\Supplier;
use Lg\Pack\Trade\model\Warehouse;
use think\facade\Request;
use think\Response;

/**
 * 采购管理控制器。
 *
 * 权限点：trade:purchase:create / trade:purchase:inbound / trade:purchase:return
 */
class Purchase extends TradeController
{
    public function __construct(private PurchaseService $service)
    {
    }

    public function index(): Response
    {
        return $this->render('purchase/order');
    }

    /** GET /trade/purchase/rows —— 采购订单分页 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $cond = [
            'status'   => $args['status'],
            'order_no' => $args['keyword'],
        ];
        $data = $this->service->page($cond, $args['page'], $args['pageSize']);
        $data['list'] = $this->withStatusLabels($data['list'], 'purchase_order');
        return $this->jsonOk($data);
    }

    /** GET /trade/purchase/export —— 采购订单导出（CSV，按浏览器下载） */
    public function export(): Response
    {
        $args = $this->pageArgs();
        $data = $this->service->page([
            'status'   => $args['status'],
            'order_no' => $args['keyword'],
        ], 1, 100000);
        $rows = $this->withStatusLabels($data['list'], 'purchase_order');

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'order_no'            => $row['order_no'],
                'supplier_name'       => $row['supplier_name'] ?: ($row['supplier_id'] ?: ''),
                'expected_arrival_at' => $row['expected_arrival_at'] ?? '',
                'warehouse_name'      => $row['warehouse_name'] ?? '',
                'total_amount'        => $row['total_amount'],
                'inbound_no'          => $row['inbound_no'],
                'status'              => $row['status_label'],
                'created_by'          => $row['created_by'] ?? '',
            ];
        }
        $header = [
            'order_no'            => '订单号',
            'supplier_name'       => '供应商',
            'expected_arrival_at' => '预计到货',
            'warehouse_name'      => '仓库',
            'total_amount'        => '总额',
            'inbound_no'          => '已入库行数',
            'status'              => '状态',
            'created_by'          => '创建人',
        ];
        return $this->csvResponse($out, $header, '采购订单');
    }

    /** GET /trade/purchase/detail —— 采购单明细（含明细行） */
    public function detail(): Response
    {
        $data = $this->service->detail((int) Request::param('id', 0));
        if ($data) {
            $data['status_label'] = $this->labels()->status('purchase_order', $data['status'] ?? null);
        }
        return $data ? $this->jsonOk($data) : $this->jsonFail('采购订单不存在');
    }

    /** POST /trade/purchase/save —— 新建草稿 */
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

    /** POST /trade/purchase/inbound —— 提交并入库 */
    public function inbound(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $rows = json_decode(Request::post('rows', '[]'), true) ?: [];
            $order = PurchaseOrder::find($id);
            if ($order && 'draft' === (string) $order->status) {
                $this->service->submit($id);
            }
            $this->service->inbound($id, $rows, $this->operator());
            return $this->jsonOk(null, '入库成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/purchase/return —— 采购退货（扣减库存，TYPE_RETURN_OUT） */
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

    /** POST /trade/purchase/cancel —— 关闭 / 作废 */
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

    // ---------------- 采购跟踪（V_1.1，PRD 3.2.4） ----------------

    /** GET /trade/purchase/track —— 采购跟踪页 */
    public function track(): Response
    {
        return $this->render('purchase/track');
    }

    /** GET /trade/purchase/trackRows —— 在途/部分到货/超期三色清单 */
    public function trackRows(): Response
    {
        $data = $this->service->track([
            'supplier_id' => (int) $this->arg('supplier_id', 0),
        ]);
        // 四分组行（transit/partial/arrival_alert/overdue）统一附状态标签
        foreach (['transit', 'partial', 'arrival_alert', 'overdue'] as $group) {
            $data[$group] = $this->withStatusLabels($data[$group] ?? [], 'purchase_order');
        }
        $suppliers = Supplier::order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        $data['suppliers'] = $suppliers;
        return $this->jsonOk($data);
    }

    // ---------------- 采购入库独立页（V_1.1，PRD 3.2.2） ----------------

    /** GET /trade/purchase/inboundPage —— 采购入库独立页 */
    public function inboundPage(): Response
    {
        return $this->render('purchase/inbound');
    }

    /** GET /trade/purchase/inboundRows —— 待入库订单分页（已提交/部分入库、未完成） */
    public function inboundRows(): Response
    {
        $args = $this->pageArgs();
        $query = PurchaseOrder::with(['items'])
            ->whereIn('status', ['submitted', 'partial'])
            ->order('id', 'desc');
        $total = $query->count();
        $list  = $query->page($args['page'], $args['pageSize'])->select()->toArray();
        $list = $this->withStatusLabels($list, 'purchase_order');
        $this->attachOrderRows($list, true);
        return $this->jsonOk(['total' => $total, 'list' => $list]);
    }

    // ---------------- 采购退货独立页（V_1.1，PRD 3.2.3） ----------------

    /** GET /trade/purchase/returnPage —— 采购退货独立页 */
    public function returnPage(): Response
    {
        return $this->render('purchase/return');
    }

    /** GET /trade/purchase/returnRows —— 待退货订单分页（已完成/部分入库且有可退余量） */
    public function returnRows(): Response
    {
        $args = $this->pageArgs();
        $orderIds = PurchaseOrderItem::whereColumn('received_qty', '>', 'returned_qty')
            ->column('order_id');
        if (empty($orderIds)) {
            return $this->jsonOk(['total' => 0, 'list' => []]);
        }
        $query = PurchaseOrder::with(['items'])
            ->whereIn('id', $orderIds)
            ->order('id', 'desc');
        $total = $query->count();
        $list  = $query->page($args['page'], $args['pageSize'])->select()->toArray();
        $list = $this->withStatusLabels($list, 'purchase_order');
        $this->attachOrderRows($list, false);
        return $this->jsonOk(['total' => $total, 'list' => $list]);
    }

    /** 为订单列表逐条附加 supplier_name / warehouse_name 与可处理明细行 rows */
    private function attachOrderRows(array &$list, bool $forInbound): void
    {
        $supplierNames = [];
        $supplierIds   = array_unique(array_column($list, 'supplier_id'));
        foreach (array_filter($supplierIds) as $sid) {
            $supplierNames[$sid] = Supplier::where('id', (int) $sid)->value('name');
        }
        $warehouseIds = array_values(array_unique(array_filter(array_column($list, 'warehouse_id'))));
        $warehouseNames = $warehouseIds ? Warehouse::whereIn('id', $warehouseIds)->column('name', 'id') : [];
        $productIds = [];
        foreach ($list as $r) {
            foreach (($r['items'] ?? []) as $it) {
                $productIds[(int) $it['product_id']] = 1;
            }
        }
        $productNames = $productIds
            ? Product::whereIn('id', array_keys($productIds))->column('name', 'id')
            : [];

        foreach ($list as &$row) {
            $row['supplier_name'] = $row['supplier_id'] ? ($supplierNames[$row['supplier_id']] ?? '') : '';
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $row['warehouse_name'] = $warehouseId && isset($warehouseNames[$warehouseId])
                ? (string) $warehouseNames[$warehouseId] : '';
            $rows = [];
            foreach (($row['items'] ?? []) as $it) {
                $received = (float) $it['received_qty'];
                $returned = (float) $it['returned_qty'];
                $qty      = (float) $it['quantity'];
                if ($forInbound) {
                    // 入库侧：取"未收"> 0 的行
                    $pending = round($qty - $received, 4);
                    if ($pending <= 0) {
                        continue;
                    }
                    $rowTag = [
                        'itemId'     => (int) $it['id'],
                        'product_id' => (int) $it['product_id'],
                        'product'    => $productNames[$it['product_id']] ?? ('#' . $it['product_id']),
                        'quantity'   => $qty,
                        'received'   => $received,
                        'pending'    => $pending,
                    ];
                } else {
                    // 退货侧：取"已收-已退"> 0 的行
                    $avail = round($received - $returned, 4);
                    if ($avail <= 0) {
                        continue;
                    }
                    $rowTag = [
                        'itemId'     => (int) $it['id'],
                        'product_id' => (int) $it['product_id'],
                        'product'    => $productNames[$it['product_id']] ?? ('#' . $it['product_id']),
                        'received'   => $received,
                        'returned'   => $returned,
                        'avail'      => $avail,
                    ];
                }
                $rows[] = $rowTag;
            }
            $row['rows'] = $rows;
        }
        unset($row);
    }

    // ---------------- 新建采购单下拉（V_1.1，PRD 3.1.3） ----------------

    /** GET /trade/purchase/formOptions —— 新建采购单下拉：供应商/仓库/商品（商品附带参考价回填） */
    public function formOptions(): Response
    {
        $suppliers = Supplier::order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        $warehouses = Warehouse::order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        $products = Product::with(['unit'])
            ->where('status', 1)
            ->order('id', 'asc')
            ->field('id, name, spec, unit_id, reference_cost_price')
            ->select()->toArray();
        // product 表无冗余 unit_name 列，经 unit 关联取计量单位名
        foreach ($products as &$p) {
            $p['unit_name'] = is_array($p['unit'] ?? null) ? ($p['unit']['name'] ?? '') : '';
            unset($p['unit']);
        }
        unset($p);
        return $this->jsonOk(compact('suppliers', 'warehouses', 'products'));
    }

    /** GET /trade/purchase/returnDetail —— 采购退货单详情（打印用，PRD 3.6） */
    public function returnDetail(): Response
    {
        $data = app(\Lg\Pack\Trade\Service\PurchaseReturnService::class)
            ->detail((int) $this->arg('id', 0));
        if (!$data) {
            return $this->jsonFail('采购退货单不存在');
        }
        $data['status_label'] = $this->labels()->status('purchase_return', $data['status'] ?? null);
        return $this->jsonOk($data);
    }
}