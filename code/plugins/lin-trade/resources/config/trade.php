<?php

return [
    // 林销 · 进销存 租户级配置默认值 —— 对应 PRD《林工_林销_进销存产品需求_v1.1》第八章
    // 配置键以 trade. 前缀开放给租户；宿主 config 服务读写（服务容器 id：config）。
    // 读取路径 App::make('config')->get('pack.lin-trade.某键')

    // ---- V_1.0 既有配置（11 项，沿用） ----
    // 1) 商品编码规则：auto_prefix(自动前缀SP) | manual(手动录入) —— trade.product_no.rule
    'product_no' => [
        'rule' => 'auto_prefix',
    ],

    // 2-3) 销售订单库存不足负库存 / 林客商机自动转单 —— trade.order.allow_negative_stock
    'order' => [
        'allow_negative_stock' => false,
        'auto_convert_crm'     => true,
    ],

    // 4) 盘点期间是否冻结出入库 —— trade.stocktake.lock_inventory
    // 5) 盘点差异是否需审批后调整 —— trade.stocktake.require_approval
    'stocktake' => [
        'lock_inventory'   => false,
        'require_approval' => false,
    ],

    // 6) 默认仓库名称 —— trade.warehouse.default
    'warehouse' => [
        'default' => '总仓',
    ],

    // 7) 采购订单是否需审核后生效 —— trade.purchase_order.require_audit
    'purchase_order' => [
        'require_audit' => false,
    ],

    // 8) 销售订单是否需审核后生效 —— trade.sale_order.require_audit
    'sale_order' => [
        'require_audit' => false,
    ],

    // 9) 采购入库是否允许超收 —— trade.purchase.allow_over_receive
    // 10) 预计到货日前 N 天提醒 —— trade.purchase.arrival_alert_days
    // 11) 采购超期提醒开关 —— trade.purchase.overdue_alert
    'purchase' => [
        'allow_over_receive'  => false,
        'arrival_alert_days'  => 3,
        'overdue_alert'       => 'on',
    ],

    // ---- V_1.1 新增配置（20 项） ----
    // 12) 销售订单提交是否预占库存 —— trade.order.reserve_stock
    'reserve_stock' => false,

    // 13) 销售是否允许行折扣与整单优惠 —— trade.sale.allow_discount
    'sale' => [
        'allow_discount' => true,
    ],

    // 14) 采购单自动回填供应商最近进价 —— trade.supplier.recent_price_backfill
    'supplier' => [
        'recent_price_backfill' => 'on',
    ],

    // 15) 销售退货是否需验退后才回补库存 —— trade.return.require_check
    'return' => [
        'require_check' => 'off',
    ],

    // 16) 低于最低售价是否拦截审批 —— trade.price.min_price_control
    // 17) 是否启用客户等级价 —— trade.price.level_price
    // 18) 客户等级集合 —— trade.price.levels
    // 19) 销售单自动回填客户最近成交价 —— trade.price.recent_backfill
    'price' => [
        'min_price_control' => 'on',
        'level_price'       => 'on',
        'levels'            => 'default,wholesale,vip',
        'recent_backfill'   => 'on',
    ],

    // 20-22) 客户信用三态 / 默认额度 / 手工标记收款 —— trade.credit.*
    'credit' => [
        'control'        => 'block',
        'default_limit'  => 0,
        'manual_settle'  => 'on',
    ],

    // 23-26) 呆滞天数 / 滞销周转天数 / ABC 规则 / 呆滞促销加价率 —— trade.stock.*
    'stock' => [
        'dull_days'          => 90,
        'turnover_days'      => 60,
        'abc_rule'           => '80/15/5',
        'dull_promo_margin'  => 10,
    ],

    // 27) 毛利口径：estimate(参考进价估算) | weighted(移动加权实际成本) —— trade.report.profit_method
    'report' => [
        'profit_method' => 'estimate',
    ],

    // 28-29) 导入最大行数 / 导入遇错策略 —— trade.import.*
    'import' => [
        'max_rows'      => 1000,
        'error_policy'  => 'stop',
    ],

    // 30) 默认打印模板 —— trade.print.template
    'print' => [
        'template' => 'classic',
    ],

    // 31) 草稿单保留天数 —— trade.draft.keep_days
    'draft' => [
        'keep_days' => 30,
    ],
];