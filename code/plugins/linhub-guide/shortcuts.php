<?php

declare(strict_types=1);

/**
 * 首页快捷入口声明（与 menus.php 的菜单 id 对应）。
 *
 * 工作区首页「功能入口卡片」候选池：
 *  - default  安装后默认展示的卡片（用户可在「定制」中删除），每包最多 3 个
 *  - optional 备选卡片（用户在「定制」抽屉中自行勾选），每包最多 20 个
 *
 * @return array{default: list<string>, optional: list<string>}
 */
return [
    // 默认显示卡片：功能包介绍
    'default' => [
        'linhub-guide.index',
    ],

    // 备选卡片：本包暂无其他菜单
    'optional' => [],
];
