<?php
/**
 * 林客功能包 · 模块路由（ThinkPHP 8 多模块）
 * 页面路由（菜单入口，渲染 src/view GUI）与数据接口路由（JSON）分离：
 *  - GET  页面路径 → pageXxx 方法（渲染 HTML）
 *  - POST 数据路径 → 业务方法（JSON；wd-data-grid 兼容 param 嵌套参数）
 * 行权限与功能权限在各控制器方法内强制校验（RL-4），路由层不重复。
 */
use think\facade\Route;

// ── 工作台插槽（workspace.php 声明，宿主首页 home.top 统计卡片） ──
Route::get('lin-crm/workspace/stats', 'Lg\Pack\LinCrm\Controller\WorkspaceController@pageStats');

// ── 全局搜索 / 权限下发（GUI 打磨配套） ──────────────────────
Route::post('lin-crm/search', 'Lg\Pack\LinCrm\Controller\SearchController@search');
Route::get('lin-crm/perms',   'Lg\Pack\LinCrm\Controller\SearchController@perms');

// ── 客户管理 ────────────────────────────────────────────────
Route::get('lin-crm/customer/index',    'Lg\Pack\LinCrm\Controller\CustomerController@pageIndex'); // 页面
Route::get('lin-crm/customer/pool',     'Lg\Pack\LinCrm\Controller\CustomerController@pagePool');  // 公海页（同页复用）
Route::get('lin-crm/customer/merge',    'Lg\Pack\LinCrm\Controller\CustomerController@pageMerge'); // 合并向导页
Route::post('lin-crm/customer/rows',      'Lg\Pack\LinCrm\Controller\CustomerController@rows');      // 列表 JSON
Route::post('lin-crm/customer/detail',    'Lg\Pack\LinCrm\Controller\CustomerController@read');      // 客户 360 详情 JSON
Route::post('lin-crm/customer/save',      'Lg\Pack\LinCrm\Controller\CustomerController@save');
Route::post('lin-crm/customer/delete',    'Lg\Pack\LinCrm\Controller\CustomerController@delete');
Route::post('lin-crm/customer/claim',     'Lg\Pack\LinCrm\Controller\CustomerController@claim');
Route::post('lin-crm/customer/giveback',  'Lg\Pack\LinCrm\Controller\CustomerController@giveback');
Route::post('lin-crm/customer/release',   'Lg\Pack\LinCrm\Controller\CustomerController@release');
Route::post('lin-crm/customer/transfer',  'Lg\Pack\LinCrm\Controller\CustomerController@transfer');
Route::post('lin-crm/customer/merge',     'Lg\Pack\LinCrm\Controller\CustomerController@merge');
Route::post('lin-crm/customer/followup',  'Lg\Pack\LinCrm\Controller\CustomerController@followup');
Route::post('lin-crm/customer/batch',     'Lg\Pack\LinCrm\Controller\CustomerController@batch');
Route::post('lin-crm/customer/timeline',  'Lg\Pack\LinCrm\Controller\CustomerController@timeline');
Route::post('lin-crm/setting/import',     'Lg\Pack\LinCrm\Controller\SettingController@import');

// ── 线索管理 ────────────────────────────────────────────────
Route::get('lin-crm/lead/index',   'Lg\Pack\LinCrm\Controller\LeadController@pageLead'); // 页面
Route::get('lin-crm/lead/pool',    'Lg\Pack\LinCrm\Controller\LeadController@pageLead'); // 线索池页（同页复用）
Route::post('lin-crm/lead/rows',    'Lg\Pack\LinCrm\Controller\LeadController@rows');     // 列表 JSON
Route::post('lin-crm/lead/save',    'Lg\Pack\LinCrm\Controller\LeadController@save');
Route::post('lin-crm/lead/assign',  'Lg\Pack\LinCrm\Controller\LeadController@assign');
Route::post('lin-crm/lead/follow',  'Lg\Pack\LinCrm\Controller\LeadController@follow');
Route::post('lin-crm/lead/convert', 'Lg\Pack\LinCrm\Controller\LeadController@convert');
Route::post('lin-crm/lead/recycle', 'Lg\Pack\LinCrm\Controller\LeadController@recycle');
Route::post('lin-crm/lead/batch',    'Lg\Pack\LinCrm\Controller\LeadController@batch');
Route::post('lin-crm/lead/timeline', 'Lg\Pack\LinCrm\Controller\LeadController@timeline');

// ── 商机管理 ────────────────────────────────────────────────
Route::get('lin-crm/opportunity/index', 'Lg\Pack\LinCrm\Controller\OpportunityController@pageIndex'); // 页面
Route::post('lin-crm/opportunity/rows',    'Lg\Pack\LinCrm\Controller\OpportunityController@rows');     // 列表 JSON
Route::post('lin-crm/opportunity/funnel',  'Lg\Pack\LinCrm\Controller\OpportunityController@funnel');
Route::post('lin-crm/opportunity/stagnant','Lg\Pack\LinCrm\Controller\OpportunityController@stagnant');
Route::post('lin-crm/opportunity/save',    'Lg\Pack\LinCrm\Controller\OpportunityController@save');
Route::post('lin-crm/opportunity/stage',   'Lg\Pack\LinCrm\Controller\OpportunityController@stage');
Route::post('lin-crm/opportunity/result',  'Lg\Pack\LinCrm\Controller\OpportunityController@result');

// ── 合同管理 ────────────────────────────────────────────────
Route::get('lin-crm/contract/index',      'Lg\Pack\LinCrm\Controller\ContractController@pageContract');  // 页面
Route::get('lin-crm/contract/receivable', 'Lg\Pack\LinCrm\Controller\ContractController@pageContract');  // 应收台账页（同页复用）
Route::post('lin-crm/contract/rows',       'Lg\Pack\LinCrm\Controller\ContractController@rows');          // 列表 JSON
Route::post('lin-crm/contract/plan-rows',  'Lg\Pack\LinCrm\Controller\ContractController@planRows');      // 回款计划台账 JSON
Route::post('lin-crm/contract/save',       'Lg\Pack\LinCrm\Controller\ContractController@save');
Route::post('lin-crm/contract/submit',     'Lg\Pack\LinCrm\Controller\ContractController@submit');
Route::post('lin-crm/contract/audit',      'Lg\Pack\LinCrm\Controller\ContractController@approve');
Route::post('lin-crm/contract/change',     'Lg\Pack\LinCrm\Controller\ContractController@change');
Route::post('lin-crm/contract/plan-save',  'Lg\Pack\LinCrm\Controller\ContractController@planSave');
Route::post('lin-crm/contract/receivable', 'Lg\Pack\LinCrm\Controller\ContractController@receivable');

// ── 报表中心 ────────────────────────────────────────────────
Route::get('lin-crm/report/dashboard', 'Lg\Pack\LinCrm\Controller\ReportController@pageDashboard'); // 页面
Route::get('lin-crm/report/funnel',    'Lg\Pack\LinCrm\Controller\ReportController@pageFunnel');
Route::get('lin-crm/report/rank',      'Lg\Pack\LinCrm\Controller\ReportController@pageRank');
Route::get('lin-crm/report/aging',     'Lg\Pack\LinCrm\Controller\ReportController@pageAging');
Route::post('lin-crm/report/dashboard', 'Lg\Pack\LinCrm\Controller\ReportController@dashboard');    // 数据 JSON
Route::post('lin-crm/report/funnel',    'Lg\Pack\LinCrm\Controller\ReportController@funnel');
Route::post('lin-crm/report/rank',      'Lg\Pack\LinCrm\Controller\ReportController@rank');
Route::post('lin-crm/report/aging',     'Lg\Pack\LinCrm\Controller\ReportController@aging');
Route::post('lin-crm/report/export',    'Lg\Pack\LinCrm\Controller\ReportController@export');

// ── 系统设置 ────────────────────────────────────────────────
Route::get('lin-crm/setting/config',    'Lg\Pack\LinCrm\Controller\SettingController@pageConfig');   // 页面
Route::get('lin-crm/setting/poolrule',  'Lg\Pack\LinCrm\Controller\SettingController@pagePoolRule');
Route::get('lin-crm/setting/dict',      'Lg\Pack\LinCrm\Controller\SettingController@pageDict');
Route::get('lin-crm/setting/recycle',   'Lg\Pack\LinCrm\Controller\SettingController@pageRecycle');
Route::get('lin-crm/setting/audit',     'Lg\Pack\LinCrm\Controller\SettingController@pageAudit');
Route::post('lin-crm/setting/config',    'Lg\Pack\LinCrm\Controller\SettingController@config');      // 数据 JSON
Route::post('lin-crm/setting/poolrule',  'Lg\Pack\LinCrm\Controller\SettingController@poolRule');
Route::post('lin-crm/setting/dict',      'Lg\Pack\LinCrm\Controller\SettingController@dict');
Route::post('lin-crm/setting/recycle',   'Lg\Pack\LinCrm\Controller\SettingController@recycle');
Route::post('lin-crm/setting/audit',      'Lg\Pack\LinCrm\Controller\SettingController@auditLogs');

// ── 跟单看板（A+B：林销订单履约只读视图 + 跟单记录 + 停滞预警） ──
Route::get('lin-crm/order/index',     'Lg\Pack\LinCrm\Controller\OrderController@pageIndex');   // 页面
Route::post('lin-crm/order/rows',     'Lg\Pack\LinCrm\Controller\OrderController@rows');        // 订单分页（进度聚合+客户弱关联）
Route::post('lin-crm/order/items',    'Lg\Pack\LinCrm\Controller\OrderController@items');       // 订单行项目出库进度
Route::post('lin-crm/order/follow',   'Lg\Pack\LinCrm\Controller\OrderController@follow');      // 新增跟单记录
Route::post('lin-crm/order/timeline', 'Lg\Pack\LinCrm\Controller\OrderController@timeline');    // 跟单记录时间线

// ── 定时任务入口（宿主调度器按 AC-CUS-02-1 / NFR-PERF-06 每日调用；幂等可重跑） ──
Route::post('lin-crm/cron/release-overdue',  function () { return json(\Lg\Pack\LinCrm\Controller\CustomerController::cronReleaseOverdue()); });
Route::post('lin-crm/cron/stagnant-alert',   function () { return json(\Lg\Pack\LinCrm\Controller\OpportunityController::cronStagnantAlert()); });
Route::post('lin-crm/cron/receivable-remind',function () { return json(\Lg\Pack\LinCrm\Controller\ContractController::cronReceivableRemind()); });
Route::post('lin-crm/cron/recycle-purge',    function () { return json(\Lg\Pack\LinCrm\Controller\SettingController::cronRecyclePurge()); });
Route::post('lin-crm/cron/order-stagnant-alert', function () { return json(\Lg\Pack\LinCrm\Controller\OrderController::cronOrderStagnantAlert()); });
