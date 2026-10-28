<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\InventoryService;
use Lg\Pack\Trade\Service\ReportService;
use Lg\Pack\Trade\Service\StocktakeService;
use Lg\Pack\Trade\Service\TransferService;
use think\facade\Db;
use think\facade\Request;
use think\Response;

/**
 * 库存管理控制器。
 *
 * 权限点：trade:inventory:view / trade:inventory:stocktake / trade:inventory:transfer
 */
class Inventory extends TradeController
{
    public function __construct(
        private InventoryService $inventory,
        private StocktakeService $stocktake,
        private TransferService  $transfer,
        private ReportService    $report
    ) {
    }

    public function index(): Response
    {
        return $this->render('inventory/index');
    }

    /** GET /trade/inventory/warningPage —— 库存预警页（PG-INV-05） */
    public function warningPage(): Response
    {
        return $this->render('inventory/warning');
    }

    /** GET /trade/inventory/rows —— 实时库存分页，支持按仓库/预警过滤 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $filter = (string) $this->arg('filter', '');
        $wh     = (int) $this->arg('warehouse_id', 0);

        if ('low' === $filter) {
            // 预警过滤：库存 < 安全库存，走连表统计（不使用 with 关系，避免与 alias 冲突）
            $query = \think\facade\Db::name('lg_lin-trade_inventory')
                ->alias('i')
                ->join('lg_lin-trade_product p', 'p.id = i.product_id')
                ->whereColumn('i.quantity', '<', 'p.safety_stock')
                ->field('i.*, p.name as product_name, p.product_no, p.safety_stock');
            if ($wh) {
                $query->where('i.warehouse_id', $wh);
            }
            $total = $query->count();
            $list  = $query->order('i.id', 'desc')
                ->page($args['page'], $args['pageSize'])->select()->toArray();
            // 预警一键采购建议：补充建议补货量 = 安全库存 - 当前库存（任务3）
            foreach ($list as &$r) {
                $r['suggest_qty'] = max(0, (float) $r['safety_stock'] - (float) $r['quantity']);
                $r['suggest']     = (float) $r['suggest_qty'] > 0 ? 1 : 0;
            }
            unset($r);
            // 预警分支未走 with 关系，补仓库名（PG-INV-05 要求展示仓库列）
            $whMap = Db::name('lg_lin-trade_warehouse')->column('name', 'id');
            foreach ($list as &$r) {
                $r['warehouse_name'] = $whMap[(int) $r['warehouse_id']] ?? '-';
            }
            unset($r);
            return $this->jsonOk(compact('total', 'list'));
        }

        $query = \Lg\Pack\Trade\model\Inventory::with(['product', 'warehouse'])
            ->page($args['page'], $args['pageSize']);
        if ($wh) {
            $query->where('warehouse_id', $wh);
        }
        $total = $query->count();
        $list  = $query->order('id', 'desc')->select()->toArray();
        return $this->jsonOk(compact('total', 'list'));
    }

    /** GET /trade/inventory/logs —— 商品库存流水 */
    public function logs(): Response
    {
        $pid = (int) $this->arg('product_id', 0);
        if ($pid <= 0) {
            return $this->jsonFail('缺少商品 id');
        }
        $data = $this->inventory->logs(
            $pid,
            (int) $this->arg('warehouse_id', 0) ?: null,
            (string) $this->arg('change_type', '')
        );
        return $this->jsonOk($data);
    }

    /** GET /trade/inventory/quantities —— 实时库存量 map（product_id => qty），可按仓库过滤（开单/调拨参考） */
    public function quantities(): Response
    {
        $wh = (int) $this->arg('warehouse_id', 0);
        $query = Db::name('lg_lin-trade_inventory');
        if ($wh > 0) {
            $query->where('warehouse_id', $wh);
        }
        $map = [];
        $rows = $query->field('product_id, SUM(quantity) as qty')
            ->group('product_id')
            ->select()->toArray();
        foreach ($rows as $r) {
            $map[(string) $r['product_id']] = (float) $r['qty'];
        }
        return $this->jsonOk($map);
    }

    /** POST /trade/inventory/stocktake —— 新建盘点单 */
    public function stocktake(): Response
    {
        try {
            $wh   = (int) Request::post('warehouse_id', 0);
            $pids = json_decode(Request::post('product_ids', '[]'), true) ?: [];
            $st   = $this->stocktake->create($wh, $pids, $this->operator(), (string) Request::post('remark', ''));
            return $this->jsonOk(['id' => $st->id, 'stocktake_no' => $st->stocktake_no], '盘点单已创建');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/inventory/confirmStocktake —— 确认盘点并调整库存（可携带实盘明细） */
    public function confirmStocktake(): Response
    {
        try {
            $id = (int) Request::post('id', 0);
            // 请求可带 {item_id: actual_qty} 或 {items:[{id,actual_qty}]}，先录入实盘
            $map = json_decode(Request::post('actual_map', '[]'), true) ?: [];
            $items = json_decode(Request::post('items', '[]'), true) ?: [];
            foreach ($items as $it) {
                if (!empty($it['id']) && isset($it['actual_qty'])) {
                    $map[(int) $it['id']] = (float) $it['actual_qty'];
                }
            }
            if ($map) {
                $this->stocktake->recordActual($id, $map);
            }
            $this->stocktake->confirm($id);
            $this->stocktake->adjust($id, $this->operator());
            return $this->jsonOk(null, '盘点调整完成');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/inventory/transfer —— 仓库调拨 */
    public function transfer(): Response
    {
        try {
            $from   = (int) Request::post('from_warehouse_id', 0);
            $to     = (int) Request::post('to_warehouse_id', 0);
            $items  = json_decode(Request::post('items', '[]'), true) ?: [];
            $t      = $this->transfer->create($from, $to, $items, $this->operator(), (string) Request::post('remark', ''));
            $this->transfer->complete($t->id, $this->operator());
            return $this->jsonOk(['id' => $t->id, 'transfer_no' => $t->transfer_no], '调拨完成');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    // ---------------- 盘点管理（PRD 3.4.3）独立页 ----------------

    /** GET /trade/inventory/stocktakePage —— 盘点管理页 */
    public function stocktakePage(): Response
    {
        return $this->render('inventory/stocktake');
    }

    /** GET /trade/inventory/stocktakeRows —— 盘点单列表（单号/仓库/状态/盘点人/盘点时间） */
    public function stocktakeRows(): Response
    {
        $args = $this->pageArgs();
        $data = $this->stocktake->page(
            ['status' => $args['status']],
            $args['page'],
            $args['pageSize']
        );

        // 补仓库名 + 明细分部（材料名/账面/实盘/差异），供前端点看
        $whMap = Db::name('lg_lin-trade_warehouse')->column('name', 'id');
        foreach ($data['list'] as &$st) {
            $st['warehouse_name'] = $whMap[(int) $st['warehouse_id']] ?? '-';
            $st['status_name']    = $this->labels()->status('stocktake', $st['status'] ?? null);
            if (!empty($st['id'])) {
                $st['items'] = Db::name('lg_lin-trade_stocktake_item')
                    ->alias('si')
                    ->join('lg_lin-trade_product p', 'p.id = si.product_id', 'left')
                    ->where('si.stocktake_id', $st['id'])
                    ->field('si.id, si.product_id, p.name, p.product_no, si.book_qty, si.actual_qty, si.diff_qty')
                    ->select()->toArray();
            }
        }
        unset($st);
        return $this->jsonOk($data);
    }

    /** GET /trade/inventory/transferPage —— 库存调拨页 */
    public function transferPage(): Response
    {
        return $this->render('inventory/transfer');
    }

    /** GET /trade/inventory/transferRows —— 调拨单/在途列表 */
    public function transferRows(): Response
    {
        $args = $this->pageArgs();
        $data = $this->transfer->page(
            ['status' => $args['status']],
            $args['page'],
            $args['pageSize']
        );

        $whMap = Db::name('lg_lin-trade_warehouse')->column('name', 'id');
        foreach ($data['list'] as &$t) {
            $t['from_warehouse_name'] = $whMap[(int) $t['from_warehouse_id']] ?? '-';
            $t['to_warehouse_name']   = $whMap[(int) $t['to_warehouse_id']] ?? '-';
            $t['status_name']         = $this->labels()->status('transfer', $t['status'] ?? null);
        }
        unset($t);
        return $this->jsonOk($data);
    }

    /** GET /trade/inventory/stocktakeDetail —— 盘点单只读明细（打印用） */
    public function stocktakeDetail(): Response
    {
        $id = (int) $this->arg('id', 0);
        $st = \Lg\Pack\Trade\model\Stocktake::find($id);
        if (!$st) {
            return $this->jsonFail('盘点单不存在');
        }
        $data = $st->toArray();
        $data['warehouse_name'] = Db::name('lg_lin-trade_warehouse')->where('id', (int) ($data['warehouse_id'] ?? 0))->value('name');
        $data['status_name']    = $this->labels()->status('stocktake', $data['status'] ?? null);
        $data['items'] = Db::name('lg_lin-trade_stocktake_item')
            ->alias('si')
            ->join('lg_lin-trade_product p', 'p.id = si.product_id', 'left')
            ->where('si.stocktake_id', $id)
            ->field('si.product_id, p.name as product_name, p.product_no, si.book_qty, si.actual_qty, si.diff_qty')
            ->select()->toArray();
        return $this->jsonOk($data);
    }

    /** GET /trade/inventory/transferDetail —— 调拨单只读明细（打印用） */
    public function transferDetail(): Response
    {
        $id = (int) $this->arg('id', 0);
        $t  = \Lg\Pack\Trade\model\Transfer::find($id);
        if (!$t) {
            return $this->jsonFail('调拨单不存在');
        }
        $data = $t->toArray();
        $whMap = Db::name('lg_lin-trade_warehouse')->column('name', 'id');
        $data['from_warehouse_name'] = $whMap[(int) ($data['from_warehouse_id'] ?? 0)] ?? '-';
        $data['to_warehouse_name']   = $whMap[(int) ($data['to_warehouse_id'] ?? 0)] ?? '-';
        $data['status_name']         = $this->labels()->status('transfer', $data['status'] ?? null);
        $data['items'] = Db::name('lg_lin-trade_transfer_item')
            ->alias('ti')
            ->join('lg_lin-trade_product p', 'p.id = ti.product_id', 'left')
            ->where('ti.transfer_id', $id)
            ->field('ti.product_id, p.name as product_name, p.product_no, ti.quantity')
            ->select()->toArray();
        return $this->jsonOk($data);
    }

    // ---------------- ABC 分类（PRD 3.4.7）独立页 ----------------

    /** GET /trade/inventory/abc —— ABC 分类分析页 */
    public function abc(): Response
    {
        return $this->render('inventory/abc');
    }

    /** GET /trade/inventory/abcRows —— 触发分类 + 返回 A/B/C 商品明细 */
    public function abcRows(): Response
    {
        // 每次进入先重算/触发分类（幂等：分类阈值由配置决定）
        $class = (string) $this->arg('class', '');
        $stats = $this->report->abcClassify();

        $list = $this->report->abcProducts($class);
        return $this->jsonOk([
            'stats'  => $stats,
            'class'  => $class,
            'list'   => $list,
        ]);
    }

    // ---------------- 呆滞清单（V_1.1，PRD 3.4.6） ----------------

    /** GET /trade/inventory/dull —— 呆滞清单页 */
    public function dull(): Response
    {
        return $this->render('inventory/dull');
    }

    /** GET /trade/inventory/dullRows —— 呆滞清单（占用金额/建议促销价） */
    public function dullRows(): Response
    {
        $data = $this->report->dullStock(
            (int) $this->arg('days', 90),
            (int) $this->arg('limit', 100)
        );
        return $this->jsonOk($data);
    }

    /** GET /trade/warehouse/options —— 仓库下拉（供销售/采购/盘点等选择） */
    public function warehouseOptions(): Response
    {
        $list = \Lg\Pack\Trade\model\Warehouse::order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        return $this->jsonOk($list);
    }
}