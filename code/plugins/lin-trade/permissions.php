<?php

declare(strict_types=1);

/**
 * 权限点声明（A4/J7/J8）：code 三段式「产品:资源:操作」
 * 产品=进销存(trade)；本品默认对所有角色可见可授权（roles 省略 = 不限）。
 * PRD《林工_林销_进销存产品需求_v1.1》第二章：19 既有 + 11 新增。
 */

return [
    // 商品档案
    ['code' => 'trade:product:view',   'title' => '查看商品档案', 'group' => '商品管理', 'description' => '查看商品档案列表与详情'],
    ['code' => 'trade:product:create', 'title' => '新建商品',     'group' => '商品管理', 'description' => '新建商品档案'],
    ['code' => 'trade:product:edit',   'title' => '编辑商品',     'group' => '商品管理', 'description' => '编辑商品档案（生效商品仅可停用）'],
    ['code' => 'trade:product:delete', 'title' => '删除商品',     'group' => '商品管理', 'description' => '删除商品档案（有单据引用的禁止删除）'],

    // 供应商档案
    ['code' => 'trade:supplier:create', 'title' => '新建供应商',   'group' => '供应商管理', 'description' => '新建供应商档案'],
    ['code' => 'trade:supplier:edit',   'title' => '编辑供应商',   'group' => '供应商管理', 'description' => '编辑/停用供应商档案'],

    // 采购
    ['code' => 'trade:purchase:create',  'title' => '创建采购订单', 'group' => '采购管理', 'description' => '创建采购订单'],
    ['code' => 'trade:purchase:inbound', 'title' => '采购入库',     'group' => '采购管理', 'description' => '对采购订单执行入库'],
    ['code' => 'trade:purchase:return',  'title' => '采购退货',     'group' => '采购管理', 'description' => '对采购订单执行退货'],
    ['code' => 'trade:purchase:audit',   'title' => '审核采购订单', 'group' => '采购管理', 'description' => '审核采购订单（开启单据审核流时使用）'],

    // 销售
    ['code' => 'trade:sale:create',   'title' => '创建销售订单', 'group' => '销售管理', 'description' => '创建销售订单'],
    ['code' => 'trade:sale:outbound', 'title' => '销售出库',     'group' => '销售管理', 'description' => '对销售订单执行出库'],
    ['code' => 'trade:sale:return',   'title' => '销售退货',     'group' => '销售管理', 'description' => '对销售订单执行退货'],
    ['code' => 'trade:sale:audit',    'title' => '审核销售订单', 'group' => '销售管理', 'description' => '审核销售订单（开启单据审核流时使用）'],

    // 库存
    ['code' => 'trade:inventory:view',      'title' => '查询库存', 'group' => '库存管理', 'description' => '查询实时库存'],
    ['code' => 'trade:inventory:stocktake', 'title' => '库存盘点', 'group' => '库存管理', 'description' => '发起并确认盘点'],
    ['code' => 'trade:inventory:transfer',  'title' => '库存调拨', 'group' => '库存管理', 'description' => '仓库间库存调拨'],

    // 报表
    ['code' => 'trade:report:view',   'title' => '查看报表', 'group' => '报表统计', 'description' => '查看进销存/库存/销售排行报表'],
    ['code' => 'trade:report:export', 'title' => '导出报表', 'group' => '报表统计', 'description' => '导出进销存/对账报表'],

    // ---- V_1.1 新增权限（11 项） ----
    ['code' => 'trade:return:check',         'title' => '销售退货验退',       'group' => '销售管理', 'description' => '销售退货验退确认（配置 trade.return.require_check 开启时使用）'],
    ['code' => 'trade:price:view',           'title' => '查看价格体系',       'group' => '价格体系', 'description' => '查看等级价/最低售价/促销价'],
    ['code' => 'trade:price:manage',         'title' => '维护价格体系',       'group' => '价格体系', 'description' => '维护等级价、最低售价、促销价'],
    ['code' => 'trade:price:approve',        'title' => '超最低售价审批',     'group' => '价格体系', 'description' => '审批低于最低售价的销售单'],
    ['code' => 'trade:credit:view',          'title' => '查看客户信用',       'group' => '客户信用', 'description' => '查看客户信用额度与占用'],
    ['code' => 'trade:credit:manage',        'title' => '调整客户信用额度',   'group' => '客户信用', 'description' => '调整客户信用额度'],
    ['code' => 'trade:reconciliation:generate', 'title' => '生成对账单',      'group' => '对账管理', 'description' => '生成客户/供应商对账单'],
    ['code' => 'trade:reconciliation:view',  'title' => '查看对账单',         'group' => '对账管理', 'description' => '查看对账单'],
    ['code' => 'trade:import:execute',       'title' => '批量导入',           'group' => '工具中心', 'description' => '执行 Excel 批量导入（含期初库存）'],
    ['code' => 'trade:draft:manage',         'title' => '管理草稿单',         'group' => '工具中心', 'description' => '草稿单转正式/删除'],
    ['code' => 'trade:doc:print',            'title' => '单据打印',           'group' => '工具中心', 'description' => '打印采购/销售/退货等单据'],
];