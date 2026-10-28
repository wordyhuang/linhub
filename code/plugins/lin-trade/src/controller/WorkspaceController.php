<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\ReportService;
use think\facade\Db;
use think\Response;
use think\facade\View;

/**
 * 工作台插槽控制器：向宿主首页输出业务概览统计卡（workspace.php 声明，zone=home.top）。
 * 页面为 iframe 装载，由本控制器服务端直出统计数据（不做异步取数，
 * 保证宿主 onSlotFrameLoad 量取 iframe 内容高度时数据已渲染完成）。
 *
 * 权限点：trade:report:view（卡片显隐由宿主按插槽 perms 过滤）。
 */
final class WorkspaceController extends TradeController
{
    /** 业务概览统计插槽页（/trade/workspace/stats） */
    public function pageStats(): Response
    {
        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        $today      = date('Y-m-d');

        // 库存总览复用报表服务（商品数/在库商品数/总量/库存金额近似值）
        $stock = $this->report()->stockOverview();

        // 低库存预警精确数（stockOverview 内预览限量 20，此处单独精确统计商品数）
        $low = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereColumn('i.quantity', '<', 'p.safety_stock')
            ->where('p.status', 1)
            ->field('COUNT(DISTINCT i.product_id) as c')
            ->find();

        // 本月采购/销售汇总（排除草稿/作废，与报表 orderSummary 口径一致）
        $po = Db::name('lg_lin-trade_purchase_order')
            ->where('status', 'not in', ['draft', 'voided'])
            ->whereBetween('created_at', [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59'])
            ->field('COUNT(*) as c, IFNULL(SUM(total_amount),0) as amt')
            ->find();
        $so = Db::name('lg_lin-trade_sale_order')
            ->where('status', 'not in', ['draft', 'voided'])
            ->whereBetween('created_at', [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59'])
            ->field('COUNT(*) as c, IFNULL(SUM(total_amount),0) as amt')
            ->find();

        // 今日出入库量（库存流水正负向聚合）
        $todayFlow = Db::name('lg_lin-trade_inventory_log')
            ->where('change_qty', '<>', 0)
            ->whereBetween('created_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
            ->field("SUM(CASE WHEN change_qty > 0 THEN change_qty ELSE 0 END) as in_qty,
                     SUM(CASE WHEN change_qty < 0 THEN -change_qty ELSE 0 END) as out_qty")
            ->find();

        $lowCount = (int) ($low['c'] ?? 0);

        return $this->renderStats([
            'v_product_total'  => number_format((float) ($stock['product_count'] ?? 0)),
            'v_stock_products' => number_format((float) ($stock['stock_products'] ?? 0)),
            'v_stock_qty'      => number_format((float) ($stock['total_qty'] ?? 0)),
            'v_stock_amount'   => number_format((float) ($stock['stock_amount'] ?? 0), 2),
            'v_low_count'      => (string) $lowCount,
            'v_low_warn'       => $lowCount > 0 ? ' warn' : '',
            'v_po_cnt'         => (string) (int) ($po['c'] ?? 0),
            'v_po_amount'      => number_format((float) ($po['amt'] ?? 0), 2),
            'v_so_cnt'         => (string) (int) ($so['c'] ?? 0),
            'v_so_amount'      => number_format((float) ($so['amt'] ?? 0), 2),
            'v_today_in'       => number_format((float) ($todayFlow['in_qty'] ?? 0)),
            'v_today_out'      => number_format((float) ($todayFlow['out_qty'] ?? 0)),
        ]);
    }

    private function report(): ReportService
    {
        return new ReportService();
    }

    /**
     * 服务端直出统计视图（走 Think 模板引擎注入变量）。
     * 基类 render() 对存在的文件静态直读、不解析模板变量，故此处独立渲染。
     */
    private function renderStats(array $data): Response
    {
        $viewPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        View::engine()->config(['view_path' => $viewPath]);
        $html = View::fetch('/workspace/stats', $data);

        return response($html)->contentType('text/html; charset=utf-8');
    }
}
