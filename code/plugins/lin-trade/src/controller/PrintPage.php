<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use think\Response;

/**
 * 单据打印页（V_1.1，PRD 3.6 / PG-TOOL-03）。
 *
 * GET /trade/print/index?type=...&id=<单据ID>&tpl=classic|compact
 * 页面为纯 HTML（src/view/print/index.html），数据由前端按 type 调对应 JSON 接口：
 *  - sale            -> /trade/sale/detail
 *  - purchase        -> /trade/purchase/detail
 *  - purchase_return -> /trade/purchase/returnDetail
 *  - sale_return     -> /trade/sale/returnDetail
 *  - stocktake       -> /trade/inventory/stocktakeDetail
 *  - transfer        -> /trade/inventory/transferDetail
 *  - reconciliation  -> /trade/report/reconciliationDetail
 * GET /trade/print/template —— 打印模板设置页（classic A4 / compact 58mm）
 */
class PrintPage extends TradeController
{
    public function index(): Response
    {
        return $this->render('print/index');
    }

    /** GET /trade/print/template —— 打印模板设置页（PG-TOOL-03） */
    public function template(): Response
    {
        return $this->render('print/template');
    }
}
