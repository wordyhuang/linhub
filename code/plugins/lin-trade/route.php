<?php

declare(strict_types=1);

/**
 * 包路由声明（协议规范 / 开发指引 07）
 * 控制器用全限定类名；由宿主 PackageRouteLoader 装配到共享 Route 实例。
 */
use think\facade\Route;

// 商品管理
Route::get('trade/product/index', 'Lg\\Pack\\Trade\\controller\\Product@index');
Route::get('trade/product/rows', 'Lg\\Pack\\Trade\\controller\\Product@rows');
Route::post('trade/product/save', 'Lg\\Pack\\Trade\\controller\\Product@save');
Route::post('trade/product/switch', 'Lg\\Pack\\Trade\\controller\\Product@toggle');
Route::get('trade/product/options', 'Lg\\Pack\\Trade\\controller\\Product@options');

// 供应商档案（PRD 3.1.3）
Route::get('trade/supplier/index', 'Lg\\Pack\\Trade\\controller\\Supplier@index');
Route::get('trade/supplier/rows', 'Lg\\Pack\\Trade\\controller\\Supplier@rows');
Route::post('trade/supplier/save', 'Lg\\Pack\\Trade\\controller\\Supplier@save');
Route::post('trade/supplier/switch', 'Lg\\Pack\\Trade\\controller\\Supplier@toggle');
Route::get('trade/supplier/options', 'Lg\\Pack\\Trade\\controller\\Supplier@options');

// 单位（PRD 3.1.2）
Route::get('trade/unit/index', 'Lg\\Pack\\Trade\\controller\\Unit@index');
Route::get('trade/unit/rows', 'Lg\\Pack\\Trade\\controller\\Unit@rows');
Route::post('trade/unit/save', 'Lg\\Pack\\Trade\\controller\\Unit@save');
Route::post('trade/unit/switch', 'Lg\\Pack\\Trade\\controller\\Unit@toggle');
Route::get('trade/unit/options', 'Lg\\Pack\\Trade\\controller\\Unit@options');

// 商品分类（PRD 3.1.2）
Route::get('trade/category/index', 'Lg\\Pack\\Trade\\controller\\Category@index');
Route::get('trade/category/rows', 'Lg\\Pack\\Trade\\controller\\Category@rows');
Route::post('trade/category/save', 'Lg\\Pack\\Trade\\controller\\Category@save');
Route::post('trade/category/switch', 'Lg\\Pack\\Trade\\controller\\Category@toggle');
Route::get('trade/category/options', 'Lg\\Pack\\Trade\\controller\\Category@options');

// 价格体系（V_1.1，PRD 3.8）
Route::get('trade/product/price', 'Lg\\Pack\\Trade\\controller\\Product@price');
Route::get('trade/product/priceRules', 'Lg\\Pack\\Trade\\controller\\Product@priceRules');
Route::post('trade/product/priceResolve', 'Lg\\Pack\\Trade\\controller\\Product@priceResolve');
Route::post('trade/product/priceSet', 'Lg\\Pack\\Trade\\controller\\Product@priceSet');
Route::post('trade/product/priceApprove', 'Lg\\Pack\\Trade\\controller\\Product@priceApprove');

// 采购管理（PRD 3.2，拆独立菜单页）
Route::get('trade/purchase/index', 'Lg\\Pack\\Trade\\controller\\Purchase@index');      // 采购订单
Route::get('trade/purchase/rows', 'Lg\\Pack\\Trade\\controller\\Purchase@rows');
Route::get('trade/purchase/export', 'Lg\\Pack\\Trade\\controller\\Purchase@export');
Route::get('trade/purchase/detail', 'Lg\\Pack\\Trade\\controller\\Purchase@detail');
Route::post('trade/purchase/save', 'Lg\\Pack\\Trade\\controller\\Purchase@save');
Route::post('trade/purchase/inbound', 'Lg\\Pack\\Trade\\controller\\Purchase@inbound');
Route::post('trade/purchase/return', 'Lg\\Pack\\Trade\\controller\\Purchase@ret');
Route::post('trade/purchase/cancel', 'Lg\\Pack\\Trade\\controller\\Purchase@cancel');
// 采购入库（PRD 3.2.2）独立页
Route::get('trade/purchase/inboundPage', 'Lg\\Pack\\Trade\\controller\\Purchase@inboundPage');
Route::get('trade/purchase/inboundRows', 'Lg\\Pack\\Trade\\controller\\Purchase@inboundRows');
// 采购退货（PRD 3.2.3）独立页
Route::get('trade/purchase/returnPage', 'Lg\\Pack\\Trade\\controller\\Purchase@returnPage');
Route::get('trade/purchase/returnRows', 'Lg\\Pack\\Trade\\controller\\Purchase@returnRows');
// 采购档单新建下拉（供应商/仓库/商品）
Route::get('trade/purchase/formOptions', 'Lg\\Pack\\Trade\\controller\\Purchase@formOptions');

// 采购跟踪（V_1.1，PRD 3.2.4）
Route::get('trade/purchase/track', 'Lg\\Pack\\Trade\\controller\\Purchase@track');
Route::get('trade/purchase/trackRows', 'Lg\\Pack\\Trade\\controller\\Purchase@trackRows');

// 销售管理
Route::get('trade/sale/index', 'Lg\\Pack\\Trade\\controller\\Sale@index');
Route::get('trade/sale/rows', 'Lg\\Pack\\Trade\\controller\\Sale@rows');
Route::get('trade/sale/export', 'Lg\\Pack\\Trade\\controller\\Sale@export');
Route::get('trade/sale/detail', 'Lg\\Pack\\Trade\\controller\\Sale@detail');
Route::post('trade/sale/save', 'Lg\\Pack\\Trade\\controller\\Sale@save');
Route::post('trade/sale/outbound', 'Lg\\Pack\\Trade\\controller\\Sale@outbound');
Route::post('trade/sale/return', 'Lg\\Pack\\Trade\\controller\\Sale@ret');
Route::post('trade/sale/cancel', 'Lg\\Pack\\Trade\\controller\\Sale@cancel');
Route::post('trade/sale/batchCancel', 'Lg\\Pack\\Trade\\controller\\Sale@batchCancel');

// 客户信用（V_1.1，PRD 3.9）
Route::get('trade/sale/credit', 'Lg\\Pack\\Trade\\controller\\Sale@credit');
Route::get('trade/sale/creditRows', 'Lg\\Pack\\Trade\\controller\\Sale@creditRows');
Route::post('trade/sale/credit/setLimit', 'Lg\\Pack\\Trade\\controller\\Sale@creditSetLimit');
Route::post('trade/sale/credit/settle', 'Lg\\Pack\\Trade\\controller\\Sale@creditSettle');
Route::post('trade/sale/creditCheck', 'Lg\\Pack\\Trade\\controller\\Sale@creditCheck');
Route::get('trade/sale/customers', 'Lg\\Pack\\Trade\\controller\\Sale@customers');

// 库存管理
Route::get('trade/inventory/index', 'Lg\\Pack\\Trade\\controller\\Inventory@index');
Route::get('trade/inventory/rows', 'Lg\\Pack\\Trade\\controller\\Inventory@rows');
Route::get('trade/inventory/logs', 'Lg\\Pack\\Trade\\controller\\Inventory@logs');
// 实时库存量 map（开单选品/调拨参考）
Route::get('trade/inventory/quantities', 'Lg\\Pack\\Trade\\controller\\Inventory@quantities');
// 库存预警（V_1.1，PRD 3.4 / PG-INV-05）
Route::get('trade/inventory/warningPage', 'Lg\\Pack\\Trade\\controller\\Inventory@warningPage');
// 仓库下拉（供销售/采购/盘点等选择）
Route::get('trade/warehouse/options', 'Lg\\Pack\\Trade\\controller\\Inventory@warehouseOptions');
Route::post('trade/inventory/stocktake', 'Lg\\Pack\\Trade\\controller\\Inventory@stocktake');
Route::post('trade/inventory/confirmStocktake', 'Lg\\Pack\\Trade\\controller\\Inventory@confirmStocktake');
Route::post('trade/inventory/transfer', 'Lg\\Pack\\Trade\\controller\\Inventory@transfer');
// 盘点管理（PRD 3.4.3）独立页
Route::get('trade/inventory/stocktakePage', 'Lg\\Pack\\Trade\\controller\\Inventory@stocktakePage');
Route::get('trade/inventory/stocktakeRows', 'Lg\\Pack\\Trade\\controller\\Inventory@stocktakeRows');
// 调拨管理（PRD 3.4.4）独立页
Route::get('trade/inventory/transferPage', 'Lg\\Pack\\Trade\\controller\\Inventory@transferPage');
Route::get('trade/inventory/transferRows', 'Lg\\Pack\\Trade\\controller\\Inventory@transferRows');
// 盘点/调拨单打印数据（只读，V_1.1 PRD 3.6）
Route::get('trade/inventory/stocktakeDetail', 'Lg\\Pack\\Trade\\controller\\Inventory@stocktakeDetail');
Route::get('trade/inventory/transferDetail', 'Lg\\Pack\\Trade\\controller\\Inventory@transferDetail');
// ABC 分类（PRD 3.4.7）独立页
Route::get('trade/inventory/abc', 'Lg\\Pack\\Trade\\controller\\Inventory@abc');
Route::get('trade/inventory/abcRows', 'Lg\\Pack\\Trade\\controller\\Inventory@abcRows');

// 呆滞清单（V_1.1，PRD 3.4.6）
Route::get('trade/inventory/dull', 'Lg\\Pack\\Trade\\controller\\Inventory@dull');
Route::get('trade/inventory/dullRows', 'Lg\\Pack\\Trade\\controller\\Inventory@dullRows');

// 报表统计
Route::get('trade/report/index', 'Lg\\Pack\\Trade\\controller\\Report@index');
Route::get('trade/report/overview', 'Lg\\Pack\\Trade\\controller\\Report@overview');
Route::get('trade/report/salesRank', 'Lg\\Pack\\Trade\\controller\\Report@salesRank');
Route::get('trade/report/salesRankRows', 'Lg\\Pack\\Trade\\controller\\Report@salesRankRows');
Route::get('trade/report/summary', 'Lg\\Pack\\Trade\\controller\\Report@summary');
Route::get('trade/report/summaryRows', 'Lg\\Pack\\Trade\\controller\\Report@summaryRows');
Route::get('trade/report/export', 'Lg\\Pack\\Trade\\controller\\Report@export');

// 对账单（V_1.1，PRD 3.5）
Route::get('trade/report/reconciliation', 'Lg\\Pack\\Trade\\controller\\Report@reconciliation');
Route::get('trade/report/reconciliationList', 'Lg\\Pack\\Trade\\controller\\Report@reconciliationList');
Route::get('trade/report/reconciliationDetail', 'Lg\\Pack\\Trade\\controller\\Report@reconciliationDetail');
Route::post('trade/report/reconciliationGenerate', 'Lg\\Pack\\Trade\\controller\\Report@reconciliationGenerate');
Route::post('trade/report/reconciliationAction', 'Lg\\Pack\\Trade\\controller\\Report@reconciliationAction');
Route::get('trade/report/targets', 'Lg\\Pack\\Trade\\controller\\Report@targets');

// 工具中心（V_1.1，PRD 3.6）
Route::get('trade/toolbox/index', 'Lg\\Pack\\Trade\\controller\\Toolbox@index');
Route::get('trade/toolbox/import', 'Lg\\Pack\\Trade\\controller\\Toolbox@import');
Route::get('trade/toolbox/importOptions', 'Lg\\Pack\\Trade\\controller\\Toolbox@importOptions');
Route::post('trade/toolbox/importSubmit', 'Lg\\Pack\\Trade\\controller\\Toolbox@importSubmit');
Route::get('trade/toolbox/importLogs', 'Lg\\Pack\\Trade\\controller\\Toolbox@importLogs');
Route::get('trade/toolbox/importTemplate', 'Lg\\Pack\\Trade\\controller\\Toolbox@importTemplate');
Route::get('trade/toolbox/draft', 'Lg\\Pack\\Trade\\controller\\Toolbox@draft');
Route::get('trade/toolbox/draftRows', 'Lg\\Pack\\Trade\\controller\\Toolbox@draftRows');
Route::post('trade/toolbox/draftDelete', 'Lg\\Pack\\Trade\\controller\\Toolbox@draftDelete');
Route::post('trade/toolbox/draftEdit', 'Lg\\Pack\\Trade\\controller\\Toolbox@draftEdit');
Route::post('trade/toolbox/draftPromote', 'Lg\\Pack\\Trade\\controller\\Toolbox@draftPromote');
// 未结算清单（PRD 3.9）
Route::get('trade/sale/credit/outstandingRows', 'Lg\\Pack\\Trade\\controller\\Sale@outstandingRows');

// 单据打印（V_1.1，PRD 3.6）：打印页 + 模板设置页 + 退货单打印数据接口
Route::get('trade/print/index', 'Lg\\Pack\\Trade\\controller\\PrintPage@index');
Route::get('trade/print/template', 'Lg\\Pack\\Trade\\controller\\PrintPage@template');
Route::get('trade/purchase/returnDetail', 'Lg\\Pack\\Trade\\controller\\Purchase@returnDetail');
Route::get('trade/sale/returnDetail', 'Lg\\Pack\\Trade\\controller\\Sale@returnDetail');

// 审批与待办中心（V_1.1，PG-COM-01）：聚合只读视图，处理动作跳转各业务页
// 注意：宿主 route_complete_match=false，/trade/todos 会宽松匹配多段路径，
// 故两段页面路由必须排在三段接口路由之后注册。
Route::get('trade/todos/rows', 'Lg\\Pack\\Trade\\controller\\Todo@rows');
// 待办计数（菜单角标轮询）：三段路由，必须排在 trade/todos 两段路由之前
Route::get('trade/todos/count', 'Lg\\Pack\\Trade\\controller\\Todo@count');
// 三段页面路由供菜单侧边栏使用（两段路由会被宿主 url() 补成 /admin/trade/todos）
Route::get('trade/todos/index', 'Lg\\Pack\\Trade\\controller\\Todo@index');
Route::get('trade/todos', 'Lg\\Pack\\Trade\\controller\\Todo@index');
// 销售订单审核（sale/index.html 已在调用，此处补注册）
Route::post('trade/sale/audit', 'Lg\\Pack\\Trade\\controller\\Sale@audit');

// 工作台插槽（workspace.php 声明，宿主首页 home.top 统计卡片）
Route::get('trade/workspace/stats', 'Lg\\Pack\\Trade\\controller\\WorkspaceController@pageStats');