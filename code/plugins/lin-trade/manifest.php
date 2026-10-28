<?php

declare(strict_types=1);

/**
 * 功能包声明文件 —— 林销（进销存）功能包 manifest
 *
 * 依据《林工_基础平台_功能包协议规范_v1.2/1.3》与 PRD《林工_林销_进销存产品需求_v1.1》。
 * 本包作为林枢宿主的功能包接入点，交付：
 *  - 商品/采购/销售/库存/报表/工具中心 六大模块；
 *  - 服务提供：trade.inventory（库存查询，供林造/林备调用）、trade.doc_query（单据查询，供林账对账）；
 *  - 事件发布：lg.trade.* 系列（见 README 跨包联动说明）。
 *
 * 关于跨包订阅（PRD 5.3，如 lg.crm.deal.closed）：
 *  宿主 ManifestValidator 的 M008 已放行外部主题与宿主保留前缀（lg.platform.*），
 *  messaging 服务已落地（共享事件总线 Broker）。本包正式声明 5 个外部主题订阅，
 *  宿主启动时由 PackageRouteLoader 汇总 manifest.subscribes 装配进共享事件总线；
 *  handler 为 Lg\Pack\Trade\subscriber\Subscribers（标准契约 handle(Event $event)）。
 */
return [
    'name'        => 'lin/lin-trade',
    'short'       => 'lin-trade',
    'title'       => '林销 · 进销存',
    'version'     => '1.1.0',
    'type'        => 'app',
    'author'      => '林工',
    'description' => '林销是林工矩阵的获客入口产品，定位小微企业"第一套数字化工具"，管采购、库存、销售；本包为 P0 首批交付。',
    'access'      => 'admin', // 中后台（需登录 + RBAC），默认值

    'dependencies' => [],
    'platform'    => [
        'host' => '>=0.1.0',
        'php'  => '>=8.1',
        'db'   => 'mysql',
    ],

    'resources'   => [
        'database' => [
            ['table' => 'lg_lin-trade_category',            'type' => 'create'],
            ['table' => 'lg_lin-trade_unit',                'type' => 'create'],
            ['table' => 'lg_lin-trade_supplier',            'type' => 'create'],
            ['table' => 'lg_lin-trade_customer',            'type' => 'create'],
            ['table' => 'lg_lin-trade_product',             'type' => 'create'],
            ['table' => 'lg_lin-trade_warehouse',           'type' => 'create'],
            ['table' => 'lg_lin-trade_inventory',           'type' => 'create'],
            ['table' => 'lg_lin-trade_inventory_log',       'type' => 'create'],
            ['table' => 'lg_lin-trade_purchase_order',      'type' => 'create'],
            ['table' => 'lg_lin-trade_purchase_order_item', 'type' => 'create'],
            ['table' => 'lg_lin-trade_sale_order',          'type' => 'create'],
            ['table' => 'lg_lin-trade_sale_order_item',     'type' => 'create'],
            ['table' => 'lg_lin-trade_stocktake',           'type' => 'create'],
            ['table' => 'lg_lin-trade_stocktake_item',      'type' => 'create'],
            ['table' => 'lg_lin-trade_transfer',            'type' => 'create'],
            ['table' => 'lg_lin-trade_transfer_item',       'type' => 'create'],
            // V_1.1 新增 8 张
            ['table' => 'lg_lin-trade_purchase_return',      'type' => 'create'],
            ['table' => 'lg_lin-trade_purchase_return_item', 'type' => 'create'],
            ['table' => 'lg_lin-trade_sale_return',          'type' => 'create'],
            ['table' => 'lg_lin-trade_sale_return_item',     'type' => 'create'],
            ['table' => 'lg_lin-trade_customer_credit',      'type' => 'create'],
            ['table' => 'lg_lin-trade_price_rule',           'type' => 'create'],
            ['table' => 'lg_lin-trade_reconciliation',       'type' => 'create'],
            ['table' => 'lg_lin-trade_import_log',           'type' => 'create'],
            // V_1.1 价格审批 / 信用操作留痕（迁移 002 新增）
            ['table' => 'lg_lin-trade_price_approval',       'type' => 'create'],
            ['table' => 'lg_lin-trade_credit_log',           'type' => 'create'],
        ],
        'files'    => ['public/lin/trade/'],
    ],
    'migrations'  => 'migrations',

    'services'    => [
        'provides' => [
            // 对外提供库存查询服务（林造/林备消费）；id 命名空间段取包短名 trade
            // desc：服务简介（作用）；params：方法名 => 签名与参数说明（调用方式）
            [
                'id' => 'trade.inventory', 'class' => 'Lg\\Pack\\Trade\\Service\\InventoryService', 'version' => '1.0.0',
                'desc' => '进销存库存服务：现存量查询、入/出库变动定义（调用方需自行包裹业务事务）、批量变动与期初导入',
                'params' => [
                    'quantity'      => '(int $productId, int $warehouseId): float — 查单品单仓现存量',
                    'totalQuantity' => '(int $productId): float — 查单品全仓汇总存量',
                    'apply'         => '(string $changeType, int $productId, int $warehouseId, float $delta, ?int $refId = null, ?string $operator = null, ?string $remark = null, ?float $unitCost = null): void — 定义库存变动（入/出），delta 正入负出',
                    'applyMany'     => '(array $rows, ?string $operator = null): void — 批量库存变动，rows 每项为 apply 的参数键值数组（整组一个事务）',
                ],
            ],
            // V_1.1 单据查询服务（供林账对账）
            [
                'id' => 'trade.doc_query', 'class' => 'Lg\\Pack\\Trade\\Service\\DocQueryService', 'version' => '1.1.0',
                'desc' => '进销存单据跨包查询：采购/销售/退货单的列表与明细，供对账、报表类功能包消费',
                'params' => [
                    'query'  => '(string $type, array $cond = []): array — 按单据类型查列表；type 取 types() 返回值；$cond 支持 limit/page/start/end/target_id/customer_id/supplier_id；返回 {total,page,limit,list}',
                    'detail' => '(string $type, int $id): ?array — 查单张单据，返回 {header,items}，不存在返回 null',
                    'types'  => '(): array — 返回支持的单据类型清单',
                ],
            ],
        ],
        // requires 引用宿主内置契约 id：config/logging/id/messaging/translation（PRD 的 id_generator 对应宿主 id 服务）
        'requires' => ['config', 'logging', 'id', 'messaging', 'translation'],
    ],
    'permissions' => 'permissions.php',
    'menus'       => 'menus.php',
    'ui_replace'  => [],

    // 跨包事件订阅（PRD 5.3）：M008 已放行外部主题，宿主启动时汇总装配进共享事件总线。
    // handler 需满足宿主标准契约：无参构造 + handle(Event $event)（Subscribers 按 topic 分发）。
    'subscribes'  => [
        ['topic' => 'lg.crm.deal.closed',          'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        ['topic' => 'lg.production.issue.outbound', 'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        ['topic' => 'lg.production.issue.return',   'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        ['topic' => 'lg.quality.iqc.passed',        'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        ['topic' => 'lg.quality.iqc.failed',         'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        // V_1.1 新增订阅（矩阵既有事件）
        ['topic' => 'lg.crm.customer.created',      'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
        ['topic' => 'lg.finance.receivable.status', 'handler' => 'Lg\\Pack\\Trade\\subscriber\\Subscribers'],
    ],
];