<?php
/**
 * 林客 CRM 功能包 · manifest 接入声明
 * 依据：《林工_基础平台（林枢/LinHub）功能包协议规范》（命名空间 Lg\Pack\LinCrm、表前缀 lg_lin-crm_、
 *       静态资源 public/lgg/lin-crm/、运行目录 runtime/pack/crm/、事件主题前缀 lg.lin-crm.）
 * 与《五件套框架选型说明_v1.0》§2.3 接入点表逐项对应。
 */
return [
    // ── 包标识 ──────────────────────────────────────────────
    // M001：name 必须为 "组/包短名"（小写字母/数字/-），short 与暂存目录 lgg-pkg-crm 一致
    'name'        => 'lgg/lin-crm',
    'short'       => 'lin-crm',
    'namespace'   => 'Lg\\Pack\\LinCrm',
    'title'       => '林客 CRM',
    'version'     => '1.0.0',
    'type'        => 'app',
    'description' => '客户资产经营：客户档案/公海私海、线索跟进转化、商机漏斗、合同回款、统计报表',

    // ── 平台约束（M004：须与宿主当前版本 0.1.0 兼容） ────────
    'platform' => [
        'host' => '>=0.1.0',
        'php'  => '>=8.0',
        'db'   => 'mysql',
    ],

    // ── 依赖声明（键=已装包注册名 lin/lin-trade，值=版本约束字符串；宿主解析器按 name 匹配） ──
    // 说明：宿主依赖解析不支持 weak 语义（缺失即拦截安装）；"未安装时降级只读跳转"
    //       由本包运行时自行判断（服务/类是否存在）实现，不在 manifest 层声明。
    'dependencies' => [
        'lin/lin-trade' => '>=1.0',
    ],

    // ── 资源声明（安装时冲突检测） ───────────────────────────
    'resources' => [
        'database' => [
            'lg_lin-crm_customer',
            'lg_lin-crm_contact',
            'lg_lin-crm_lead',
            'lg_lin-crm_opportunity',
            'lg_lin-crm_opportunity_member',
            'lg_lin-crm_contract',
            'lg_lin-crm_contract_change',
            'lg_lin-crm_receivable_plan',
            'lg_lin-crm_receivable_record',
            'lg_lin-crm_follow_up',
            'lg_lin-crm_pool_rule',
            'lg_lin-crm_notify_log',
            'lg_lin-crm_audit_log',
            'lg_lin-crm_attachment',
            'lg_lin-crm_dict',
        ],
        'files'  => ['public/lgg/lin-crm/'],
        'others' => [],
    ],

    // ── 迁移（走宿主迁移引擎，禁止自建迁移工具；值必须为目录名字符串） ──
    'migrations' => 'migrations',

    // ── 服务声明（M012/M006：requires 用宿主服务真实 id；M013：provides 必须带语义化 version） ──
    'services' => [
        'provides' => [
            [
                'id'      => 'lin-crm.customer.query',
                'class'   => 'Lg\\Pack\\LinCrm\\Service\\CustomerQueryService',
                'version' => '1.0.0',
                'desc'    => '客户主档与归属查询（按 id/名称/手机号），供矩阵兄弟产品调用',
            ],
        ],
        // 宿主服务清单无 db 服务（数据访问走框架 Db 门面），只声明实际存在的服务
        'requires' => ['logging', 'config', 'recyclebin'],
    ],

    // ── 事件订阅（宿主启动时汇总路由表） ─────────────────────
    'subscribes' => [
        [
            'topic'   => 'lg.trade.order.status',
            'handler' => 'Lg\\Pack\\LinCrm\\Listener\\TradeOrderStatusListener',
            'note'    => '⛔ OI-001：契约签署前 handler 注册但不启用处理逻辑（幂等预留 BND-08）',
        ],
    ],

    // ── 声明文件 ────────────────────────────────────────────
    'permissions' => 'permissions.php',
    'menus'       => 'menus.php',

    // ── 界面替换与工作台插槽（本包不使用） ───────────────────
    'ui_replace' => [],
    'workspace'  => null,
];
