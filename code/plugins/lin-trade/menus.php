<?php

declare(strict_types=1);

/**
 * 菜单声明（A5/J6）。
 * id 以包短名 trade 作前缀避免与宿主/其他包冲突。
 * 归类遵循 04 功能包开发指引 §6.2「菜单归类指导（强制）」：
 *  - 业务操作 → menu.workspace（工作区）；
 *  - 审批与待办类 → menu.approval（审批中心，多包待办聚合）；
 *  - 统计报表类 → menu.report（报表中心，多包合并，不自建报表根）；
 *  - 无系统配置类入口，故不使用 menu.system。
 * 宿主渲染规则（实测）：父节点一旦挂了子节点，父节点自身 route 被丢弃、渲染为不可点击的折叠分组，
 * 因此每个分组都补一个「列表页/总览」叶子承载入口，否则该模块首页无法点击进入。
 * 分组 perms 取子项并集：避免父节点被 RBAC 过滤后子项被提升为顶层分组（孤儿提升）。
 *
 * V_1.1：洞察入口（采购跟踪→采购、客户信用/价格体系→商品/销售、对账单→报表、呆滞清单→库存）挂靠相应分组。
 * V_1.2：采购拆独立菜单（订单/入库/退货）；新增供应商/单位/商品分类档案；库存挂盘点/调拨/ABC；报表挂销售排行/进销存汇总。
 * V_1.3：新增库存预警/打印模板设置/审批与待办中心入口（PG-INV-05 / PG-TOOL-03 / PG-COM-01）。
 * V_1.4：销售管理改为分组并新增「销售订单」叶子入口（分组不带 path，列表页需叶子承载）；
 *        销售管理入口权限改为销售子权限并集，避免只读角色看不到菜单或「客户信用」层级漂移。
 * V_1.5：去掉一级菜单「进销存（林销）」（trade.root），业务模块直接挂工作区；
 *        报表类（报表总览/销售排行/进销存汇总/对账单）平铺挂报表中心 menu.report；
 *        价格体系归入商品管理分组；每个带子节点的分组补齐列表页叶子入口；
 *        供应商档案入口权限由不存在的 trade:supplier:view 改为实际存在的 create/edit。
 * V_1.6：全部一级菜单声明 group="进销存"，分导台侧按 group 聚合为同一标题分组
 *        （标题行不可点击，组内平铺子菜单/叶子），代替被移除的一级根菜单做视觉归组。
 * V_1.7：全部菜单项声明 icon（Element Plus 图标名，逐一经 vendor icons 包 v2.3.2 验证存在）；
 *        一级与子项均声明——group 平铺渲染后子项直接可见，图标提升辨识度。
 * V_1.8：「审批与待办中心」由工作区迁至审批中心分导台 menu.approval（多包待办聚合入口），去掉 group。
 */
return [
    // ---- 工作区：商品管理 ----
    [
        'id'     => 'trade.product',
        'parent' => 'menu.workspace',
        'title'  => '商品管理',
        'route'  => '',
        'icon'   => 'Goods',
        'group'  => '进销存',
        'order'  => 10,
        // 子项并集：商品档案/分类/单位（product:view）+ 价格体系（price:view）
        'perms'  => ['trade:product:view', 'trade:price:view'],
    ],
    [
        'id'     => 'trade.product.list',
        'parent' => 'trade.product',
        'title'  => '商品档案',
        'route'  => 'trade/product/index',
        'icon'   => 'Notebook',
        'order'  => 11,
        'perms'  => ['trade:product:view'],
    ],
    [
        'id'     => 'trade.category',
        'parent' => 'trade.product',
        'title'  => '商品分类',
        'route'  => 'trade/category/index',
        'icon'   => 'Collection',
        'order'  => 12,
        'perms'  => ['trade:product:view'],
    ],
    [
        'id'     => 'trade.unit',
        'parent' => 'trade.product',
        'title'  => '计量单位',
        'route'  => 'trade/unit/index',
        'icon'   => 'ScaleToOriginal',
        'order'  => 13,
        'perms'  => ['trade:product:view'],
    ],
    [
        'id'     => 'trade.price',
        'parent' => 'trade.product',
        'title'  => '价格体系',
        'route'  => 'trade/product/price',
        'icon'   => 'Coin',
        'order'  => 14,
        'perms'  => ['trade:price:view'],
    ],

    // ---- 工作区：供应商档案 ----
    [
        'id'     => 'trade.supplier',
        'parent' => 'menu.workspace',
        'title'  => '供应商档案',
        'route'  => 'trade/supplier/index',
        'icon'   => 'OfficeBuilding',
        'group'  => '进销存',
        'order'  => 15,
        'perms'  => ['trade:supplier:create', 'trade:supplier:edit'],
    ],

    // ---- 工作区：采购 ----
    [
        'id'     => 'trade.purchase',
        'parent' => 'menu.workspace',
        'title'  => '采购',
        'route'  => '',
        'icon'   => 'ShoppingTrolley',
        'group'  => '进销存',
        'order'  => 20,
        'perms'  => ['trade:purchase:create'],
    ],
    [
        'id'     => 'trade.purchase.orders',
        'parent' => 'trade.purchase',
        'title'  => '采购订单',
        'route'  => 'trade/purchase/index',
        'icon'   => 'ShoppingCart',
        'order'  => 21,
        'perms'  => ['trade:purchase:create'],
    ],
    [
        'id'     => 'trade.purchase.inbound',
        'parent' => 'trade.purchase',
        'title'  => '采购入库',
        'route'  => 'trade/purchase/inboundPage',
        'icon'   => 'Download',
        'order'  => 22,
        'perms'  => ['trade:purchase:create'],
    ],
    [
        'id'     => 'trade.purchase.return',
        'parent' => 'trade.purchase',
        'title'  => '采购退货',
        'route'  => 'trade/purchase/returnPage',
        'icon'   => 'Upload',
        'order'  => 23,
        'perms'  => ['trade:purchase:create'],
    ],
    [
        'id'     => 'trade.purchase.track',
        'parent' => 'trade.purchase',
        'title'  => '采购跟踪',
        'route'  => 'trade/purchase/track',
        'icon'   => 'Location',
        'order'  => 24,
        'perms'  => [],
    ],

    // ---- 工作区：销售 ----
    [
        'id'     => 'trade.sale',
        'parent' => 'menu.workspace',
        'title'  => '销售管理',
        'route'  => '',
        'icon'   => 'Sell',
        'group'  => '进销存',
        'order'  => 30,
        // 规格：订单列表入口对所有角色可见。此处列全部销售子权限，命中任一即显示；
        // 同时保证只读角色不会因父节点被过滤导致「客户信用」漂移到根级。
        'perms'  => [
            'trade:sale:create',
            'trade:sale:outbound',
            'trade:sale:return',
            'trade:return:check',
            'trade:sale:audit',
            'trade:credit:view',
        ],
    ],
    [
        'id'     => 'trade.sale.orders',
        'parent' => 'trade.sale',
        'title'  => '销售订单',
        'route'  => 'trade/sale/index',
        'icon'   => 'ShoppingBag',
        'order'  => 31,
        'perms'  => [
            'trade:sale:create',
            'trade:sale:outbound',
            'trade:sale:return',
            'trade:return:check',
            'trade:sale:audit',
            'trade:credit:view',
        ],
    ],
    [
        'id'     => 'trade.credit',
        'parent' => 'trade.sale',
        'title'  => '客户信用',
        'route'  => 'trade/sale/credit',
        'icon'   => 'CreditCard',
        'order'  => 35,
        'perms'  => ['trade:credit:view'],
    ],

    // ---- 工作区：库存 ----
    [
        'id'     => 'trade.inventory',
        'parent' => 'menu.workspace',
        'title'  => '库存管理',
        'route'  => '',
        'icon'   => 'Box',
        'group'  => '进销存',
        'order'  => 40,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.inventory.list',
        'parent' => 'trade.inventory',
        'title'  => '库存查询',
        'route'  => 'trade/inventory/index',
        'icon'   => 'Search',
        'order'  => 41,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.stocktake',
        'parent' => 'trade.inventory',
        'title'  => '库存盘点',
        'route'  => 'trade/inventory/stocktakePage',
        'icon'   => 'Checked',
        'order'  => 42,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.transfer',
        'parent' => 'trade.inventory',
        'title'  => '库存调拨',
        'route'  => 'trade/inventory/transferPage',
        'icon'   => 'Switch',
        'order'  => 43,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.abc',
        'parent' => 'trade.inventory',
        'title'  => 'ABC 分类',
        'route'  => 'trade/inventory/abc',
        'icon'   => 'PieChart',
        'order'  => 44,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.warning',
        'parent' => 'trade.inventory',
        'title'  => '库存预警',
        'route'  => 'trade/inventory/warningPage',
        'icon'   => 'Warning',
        'order'  => 45,
        'perms'  => ['trade:inventory:view'],
    ],
    [
        'id'     => 'trade.dull',
        'parent' => 'trade.inventory',
        'title'  => '呆滞清单',
        'route'  => 'trade/inventory/dull',
        'icon'   => 'Timer',
        'order'  => 46,
        'perms'  => ['trade:inventory:view'],
    ],

    // ---- 工作区：工具中心 ----
    [
        'id'     => 'trade.toolbox',
        'parent' => 'menu.workspace',
        'title'  => '工具中心',
        'route'  => '',
        'icon'   => 'Tools',
        'group'  => '进销存',
        'order'  => 60,
        'perms'  => ['trade:import:execute', 'trade:draft:manage', 'trade:doc:print'],
    ],
    [
        'id'     => 'trade.toolbox.index',
        'parent' => 'trade.toolbox',
        'title'  => '工具总览',
        'route'  => 'trade/toolbox/index',
        'icon'   => 'Grid',
        'order'  => 61,
        'perms'  => ['trade:import:execute', 'trade:draft:manage', 'trade:doc:print'],
    ],
    [
        'id'     => 'trade.toolbox.import',
        'parent' => 'trade.toolbox',
        'title'  => '批量导入',
        'route'  => 'trade/toolbox/import',
        'icon'   => 'UploadFilled',
        'order'  => 62,
        'perms'  => ['trade:import:execute'],
    ],
    [
        'id'     => 'trade.toolbox.draft',
        'parent' => 'trade.toolbox',
        'title'  => '草稿单',
        'route'  => 'trade/toolbox/draft',
        'icon'   => 'EditPen',
        'order'  => 63,
        'perms'  => ['trade:draft:manage'],
    ],
    [
        'id'     => 'trade.toolbox.print',
        'parent' => 'trade.toolbox',
        'title'  => '打印模板设置',
        'route'  => 'trade/print/template',
        'icon'   => 'Printer',
        'order'  => 64,
        'perms'  => ['trade:doc:print'],
    ],

    // ---- 审批中心：待办聚合入口 ----
    [
        'id'     => 'trade.todos',
        'parent' => 'menu.approval',
        'title'  => '审批与待办中心',
        'route'  => 'trade/todos/index',
        'icon'   => 'Tickets',
        'order'  => 10,
        'perms'  => [],
    ],

    // ---- 报表中心（多包合并，不自建报表根）----
    [
        'id'     => 'trade.report.overview',
        'parent' => 'menu.report',
        'title'  => '林销 · 报表总览',
        'route'  => 'trade/report/index',
        'icon'   => 'DataBoard',
        'group'  => '进销存',
        'order'  => 10,
        'perms'  => ['trade:report:view'],
    ],
    [
        'id'     => 'trade.report.salesRank',
        'parent' => 'menu.report',
        'title'  => '林销 · 销售排行',
        'route'  => 'trade/report/salesRank',
        'icon'   => 'Rank',
        'group'  => '进销存',
        'order'  => 11,
        'perms'  => ['trade:report:view'],
    ],
    [
        'id'     => 'trade.report.summary',
        'parent' => 'menu.report',
        'title'  => '林销 · 进销存汇总',
        'route'  => 'trade/report/summary',
        'icon'   => 'DataAnalysis',
        'group'  => '进销存',
        'order'  => 12,
        'perms'  => ['trade:report:view'],
    ],
    [
        'id'     => 'trade.reconciliation',
        'parent' => 'menu.report',
        'title'  => '林销 · 对账单',
        'route'  => 'trade/report/reconciliation',
        'icon'   => 'Wallet',
        'group'  => '进销存',
        'order'  => 13,
        'perms'  => ['trade:reconciliation:view'],
    ],
];
