<?php

declare(strict_types=1);

/**
 * 首页快捷入口声明（A5/J6）。
 * 卡片以菜单 id 引用，标题/路由/图标/权限均取自 menus.php，此处不重复展示属性。
 *  - default  安装/升级时默认加入用户首页，最多 3 个（用户可在「定制」中删除）；
 *  - optional 仅进入「定制」抽屉的备选池，最多 20 个，由用户自行勾选。
 * 宿主渲染时按当前用户可见菜单过滤：无权限的声明不会出现在候选池，左侧菜单不受影响。
 */
return [
    // 默认显示：进销存最常用的三个业务入口
    'default' => [
        'trade.sale.orders',        // 销售订单
        'trade.purchase.orders',    // 采购订单
        'trade.inventory.list',     // 库存查询
    ],

    // 备选卡片：其余可从左侧菜单进入的业务与工具入口
    'optional' => [
        'trade.product.list',       // 商品档案
        'trade.price',              // 价格体系
        'trade.supplier',           // 供应商档案
        'trade.purchase.inbound',   // 采购入库
        'trade.purchase.return',    // 采购退货
        'trade.purchase.track',     // 采购跟踪
        'trade.credit',             // 客户信用
        'trade.stocktake',          // 库存盘点
        'trade.transfer',           // 库存调拨
        'trade.abc',                // ABC 分类
        'trade.warning',            // 库存预警
        'trade.dull',               // 呆滞清单
        'trade.todos',              // 审批与待办中心
        'trade.toolbox.import',     // 批量导入
        'trade.toolbox.draft',      // 草稿单
        'trade.toolbox.print',      // 打印模板设置
        'trade.report.overview',    // 林销 · 报表总览
        'trade.report.salesRank',   // 林销 · 销售排行
        'trade.report.summary',     // 林销 · 进销存汇总
        'trade.reconciliation',     // 林销 · 对账单
    ],
];
