<?php

declare(strict_types=1);

/**
 * 林客 CRM 功能包 · 菜单声明
 *
 * 按平台标准格式编写（扁平列表，字段 id/parent/title/route/icon/order/perms，
 * 参照已验证的 code/plugins/lin-trade/menus.php），与原嵌套 children 结构不同。
 * 归类遵循 04 功能包开发指引 §6.2「菜单归类指导（强制）」：
 *  - 客户/线索/商机/合同等日常业务操作 → menu.workspace（工作区）；
 *  - 统计报表类（工作台/漏斗/排行/账龄）→ menu.report（报表中心，多包合并，不自建报表根，
 *    标题加「林客 · 」前缀避免与其他包同名报表混淆）；
 *  - 系统设置类（提醒规则/公海规则/字典/回收站/审计日志）→ menu.system（系统功能）。
 * 宿主渲染规则：父节点挂子节点后自身 route 被丢弃、默认渲染为折叠子菜单；
 * 一级菜单声明 group 时，group 值相同的节点聚合在同一个标题分组下（标题行不可点击），
 * 组内保留各节点自身——有子菜单的仍渲染为折叠子菜单，叶子仍直接可点；
 * 未声明 group 的一级菜单保持默认折叠渲染。声明变更后须重新登记才生效（registry 快照）。
 * 分组 perms 取子项并集，避免父节点被 RBAC 过滤后子项被提升为顶层（孤儿提升）。
 * route 一律裸路径（不带前导 /、不带 admin/ 前缀），与包 route.php 同一套。
 * perms 引用的权限码均在 permissions.php 中声明。
 */
return [
    // ---- 工作区：客户管理 ----
    [
        'id'     => 'lin-crm.customer',
        'parent' => 'menu.workspace',
        'title'  => '客户管理',
        'group'  => '客户关系',
        'route'  => '',
        'icon'   => 'User',
        'order'  => 10,
        // 子项并集：客户列表/公海（view）+ 客户合并（edit）
        'perms'  => ['lin-crm:customer:view', 'lin-crm:customer:edit'],
    ],
    [
        'id'     => 'lin-crm.customer.list',
        'parent' => 'lin-crm.customer',
        'title'  => '客户列表',
        'route'  => 'lin-crm/customer/index',
        'icon'   => 'Avatar',
        'order'  => 11,
        'perms'  => ['lin-crm:customer:view'],
    ],
    [
        'id'     => 'lin-crm.customer.pool',
        'parent' => 'lin-crm.customer',
        'title'  => '公海',
        'route'  => 'lin-crm/customer/pool',
        'icon'   => 'Ship',
        'order'  => 12,
        'perms'  => ['lin-crm:customer:view'],
    ],
    [
        'id'     => 'lin-crm.customer.merge',
        'parent' => 'lin-crm.customer',
        'title'  => '客户合并',
        'route'  => 'lin-crm/customer/merge',
        'icon'   => 'Connection',
        'order'  => 13,
        'perms'  => ['lin-crm:customer:edit'],
    ],

    // ---- 工作区：线索管理 ----
    [
        'id'     => 'lin-crm.lead',
        'parent' => 'menu.workspace',
        'title'  => '线索管理',
        'group'  => '客户关系',
        'route'  => '',
        'icon'   => 'Aim',
        'order'  => 20,
        'perms'  => ['lin-crm:lead:view'],
    ],
    [
        'id'     => 'lin-crm.lead.pool',
        'parent' => 'lin-crm.lead',
        'title'  => '线索池',
        'route'  => 'lin-crm/lead/pool',
        'icon'   => 'Filter',
        'order'  => 21,
        'perms'  => ['lin-crm:lead:view'],
    ],
    [
        'id'     => 'lin-crm.lead.list',
        'parent' => 'lin-crm.lead',
        'title'  => '线索列表',
        'route'  => 'lin-crm/lead/index',
        'icon'   => 'List',
        'order'  => 22,
        'perms'  => ['lin-crm:lead:view'],
    ],

    // ---- 工作区：商机管理 ----
    [
        'id'     => 'lin-crm.opportunity',
        'parent' => 'menu.workspace',
        'title'  => '商机管理',
        'group'  => '客户关系',
        'route'  => '',
        'icon'   => 'Opportunity',
        'order'  => 30,
        'perms'  => ['lin-crm:opportunity:view'],
    ],
    [
        'id'     => 'lin-crm.opportunity.list',
        'parent' => 'lin-crm.opportunity',
        'title'  => '商机列表',
        'route'  => 'lin-crm/opportunity/index',
        'icon'   => 'TrendCharts',
        'order'  => 31,
        'perms'  => ['lin-crm:opportunity:view'],
    ],

    // ---- 工作区：合同管理 ----
    [
        'id'     => 'lin-crm.contract',
        'parent' => 'menu.workspace',
        'title'  => '合同管理',
        'group'  => '客户关系',
        'route'  => '',
        'icon'   => 'Document',
        'order'  => 40,
        // 子项并集：合同列表（view）+ 回款计划（receivable:plan）
        'perms'  => ['lin-crm:contract:view', 'lin-crm:receivable:plan'],
    ],
    [
        'id'     => 'lin-crm.contract.list',
        'parent' => 'lin-crm.contract',
        'title'  => '合同列表',
        'route'  => 'lin-crm/contract/index',
        'icon'   => 'DocumentCopy',
        'order'  => 41,
        'perms'  => ['lin-crm:contract:view'],
    ],
    [
        'id'     => 'lin-crm.contract.receivable',
        'parent' => 'lin-crm.contract',
        'title'  => '回款计划',
        'route'  => 'lin-crm/contract/receivable',
        'icon'   => 'Money',
        'order'  => 42,
        'perms'  => ['lin-crm:receivable:plan'],
    ],

    // ---- 工作区：跟单看板（A+B：林销订单履约视图，叶子直挂工作区）----
    [
        'id'     => 'lin-crm.order',
        'parent' => 'menu.workspace',
        'title'  => '跟单看板',
        'group'  => '客户关系',
        'route'  => 'lin-crm/order/index',
        'icon'   => 'Van',
        'order'  => 45,
        'perms'  => ['lin-crm:order:view'],
    ],

    // ---- 报表中心（多包合并，不自建报表根，标题加「林客 · 」前缀）----
    [
        'id'     => 'lin-crm.report.dashboard',
        'parent' => 'menu.report',
        'title'  => '林客 · 工作台',
        'group'  => '客户关系',
        'route'  => 'lin-crm/report/dashboard',
        'icon'   => 'DataBoard',
        'order'  => 20,
        'perms'  => ['lin-crm:report:view'],
    ],
    [
        'id'     => 'lin-crm.report.funnel',
        'parent' => 'menu.report',
        'title'  => '林客 · 漏斗分析',
        'group'  => '客户关系',
        'route'  => 'lin-crm/report/funnel',
        'icon'   => 'Histogram',
        'order'  => 21,
        'perms'  => ['lin-crm:report:view'],
    ],
    [
        'id'     => 'lin-crm.report.rank',
        'parent' => 'menu.report',
        'title'  => '林客 · 销售排行',
        'group'  => '客户关系',
        'route'  => 'lin-crm/report/rank',
        'icon'   => 'Trophy',
        'order'  => 22,
        'perms'  => ['lin-crm:report:view'],
    ],
    [
        'id'     => 'lin-crm.report.aging',
        'parent' => 'menu.report',
        'title'  => '林客 · 应收账龄',
        'group'  => '客户关系',
        'route'  => 'lin-crm/report/aging',
        'icon'   => 'AlarmClock',
        'order'  => 23,
        'perms'  => ['lin-crm:report:view'],
    ],

    // ---- 系统功能（menu.system）：设置类入口 ----
    [
        'id'     => 'lin-crm.setting',
        'parent' => 'menu.system',
        'title'  => 'CRM 设置',
        'group'  => '客户关系',
        'route'  => '',
        'icon'   => 'Setting',
        'order'  => 30,
        // 子项并集：设置页（config:manage）+ 回收站（customer:delete）
        'perms'  => ['lin-crm:config:manage', 'lin-crm:customer:delete'],
    ],
    [
        'id'     => 'lin-crm.setting.config',
        'parent' => 'lin-crm.setting',
        'title'  => '提醒规则',
        'route'  => 'lin-crm/setting/config',
        'icon'   => 'Bell',
        'order'  => 31,
        'perms'  => ['lin-crm:config:manage'],
    ],
    [
        'id'     => 'lin-crm.setting.poolrule',
        'parent' => 'lin-crm.setting',
        'title'  => '公海规则',
        'route'  => 'lin-crm/setting/poolrule',
        'icon'   => 'SetUp',
        'order'  => 32,
        'perms'  => ['lin-crm:config:manage'],
    ],
    [
        'id'     => 'lin-crm.setting.dict',
        'parent' => 'lin-crm.setting',
        'title'  => '字典管理',
        'route'  => 'lin-crm/setting/dict',
        'icon'   => 'Collection',
        'order'  => 33,
        'perms'  => ['lin-crm:config:manage'],
    ],
    [
        'id'     => 'lin-crm.setting.recycle',
        'parent' => 'lin-crm.setting',
        'title'  => '回收站',
        'route'  => 'lin-crm/setting/recycle',
        'icon'   => 'DeleteFilled',
        'order'  => 34,
        'perms'  => ['lin-crm:customer:delete'],
    ],
    [
        'id'     => 'lin-crm.setting.audit',
        'parent' => 'lin-crm.setting',
        'title'  => '审计日志',
        'route'  => 'lin-crm/setting/audit',
        'icon'   => 'Memo',
        'order'  => 35,
        'perms'  => ['lin-crm:config:manage'],
    ],
];
