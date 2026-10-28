<?php

declare(strict_types=1);

/**
 * 宿主「分导台」声明（menus 与 slots 两个分区）。
 *
 * 分导台根组（menus 分区）由 HostBootstrap::adminMenuDefs() include 后并入 host_admin 平台菜单，
 * 所有子项集中在 host/menus.php（parent 指向本文件的分导台 id）。
 * 演示插槽（slots 分区）由 HostBootstrap::seedSlots() include，作为 host_demo seed。
 * 菜单项与功能包 menus.php 同构（A5/J6）：id/parent/title/route/icon/order/perms 七键。
 * 插槽项与功能包 workspace.php 同构：id/title/render/perms。
 *
 * @return array{menus: list<array<string, mixed>>, slots: list<array<string, mixed>>}
 */
return [
    'menus' => [
        // 分导台一：工作区（空壳根组，不预置子项；由功能包 menus.php 通过 parent=menu.workspace 挂载）
        ['id' => 'menu.workspace',  'parent' => null, 'title' => '工作区',   'route' => '', 'icon' => 'Monitor', 'order' => 10, 'perms' => []],
        // 分导台二：审批中心（空壳根组，不预置子项；由功能包 menus.php 通过 parent=menu.approval 挂载）
        ['id' => 'menu.approval',   'parent' => null, 'title' => '审批中心', 'route' => '', 'icon' => 'Checked', 'order' => 15, 'perms' => []],
        // 分导台三：系统功能
        ['id' => 'menu.system',     'parent' => null, 'title' => '系统功能', 'route' => '', 'icon' => 'Setting', 'order' => 20, 'perms' => []],
        // 分导台四：报表中心（空壳根组，不预置子项；由功能包 menus.php 通过 parent=menu.report 挂载）
        ['id' => 'menu.report',     'parent' => null, 'title' => '报表中心', 'route' => '', 'icon' => 'DataAnalysis', 'order' => 25, 'perms' => []],
        // 分导台五：超管功能（平台管理组，子项见 host/menus.php）
        ['id' => 'menu.superadmin', 'parent' => null, 'title' => '超管功能', 'route' => '', 'icon' => 'Lock', 'order' => 30, 'perms' => []],
        // 分导台六：开发辅助
        ['id' => 'menu.dev',        'parent' => null, 'title' => '开发辅助', 'route' => '', 'icon' => 'Tools', 'order' => 40, 'perms' => []],
    ],

    'slots' => [
        ['id' => 'ws.open',         'title' => '欢迎使用 LinHub', 'render' => 'welcome/open',  'perms' => []],
        ['id' => 'ws.demoview',     'title' => '演示数据看板',     'render' => 'demo/board',    'perms' => ['host:demo:view']],
        ['id' => 'ws.systemconfig', 'title' => '系统配置入口',     'render' => 'system/config', 'perms' => ['host:system:config']],
    ],
];
