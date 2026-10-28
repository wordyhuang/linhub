<?php

declare(strict_types=1);

/**
 * 林销（进销存）功能包 · 工作台插槽声明（04 指引 §6.3）
 *
 * 创建时间：2026-10-28 10:30:00  最后修改：2026-10-28 10:30:00  版本：V_1.0
 * 适用对象：宿主首页（/admin/home 及各角色首页）  核心目标：首页顶部插入业务概览统计卡
 *
 * 向宿主首页插入业务概览统计卡：
 *  - zone=home.top（功能入口卡片区上方）；render 须为单 / 开头的同源 URL；
 *  - 页面由包内 WorkspaceController@pageStats 服务端直出统计数据（避免 iframe
 *    异步取数导致宿主 onSlotFrameLoad 量高偏小，与 lin-crm 同构）；
 *  - perms 声明为可见门槛：无 trade:report:view 的用户首页不渲染该卡片。
 *
 * V_1.0：首版，声明业务概览统计插槽。
 */
return [
    [
        'id'     => 'lin-trade.stats.overview',
        'title'  => '林销 · 业务概览',
        'zone'   => 'home.top',
        'render' => '/trade/workspace/stats',
        'perms'  => ['trade:report:view'],
        'order'  => 11,
    ],
];
