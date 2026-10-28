<?php

declare(strict_types=1);

/**
 * 林客 CRM 功能包 · 工作台插槽声明
 *
 * 向宿主首页（/admin/home 及各角色首页）插入业务统计卡片（04 指引 §6.3）：
 *  - zone 缺省 home.top（功能入口卡片区上方）；render 须为单 / 开头的同源 URL；
 *  - 页面由包内 WorkspaceController@pageStats 服务端直出统计数据（避免 iframe
 *    异步取数导致 onSlotFrameLoad 量高偏小）；
 *  - perms 声明为可见门槛：无 lin-crm:customer:view 的用户既看不到卡片、
 *    页面侧 checkPerm 也会拦截（前端显隐不构成授权，RL-4）。
 */
return [
    [
        'id'     => 'lin-crm.stats.overview',
        'title'  => '林客 CRM · 业务概览',
        'zone'   => 'home.top',
        'render' => '/lin-crm/workspace/stats',
        'perms'  => ['lin-crm:customer:view'],
        'order'  => 10,
    ],
];
