<?php

declare(strict_types=1);

/**
 * 平台内置快捷入口声明（与功能包 shortcuts.php 同构）。
 *
 * 工作区首页「功能入口卡片」的候选池由各包 shortcuts.php 汇总而成：
 *  - default  安装后默认展示的卡片（用户可在「定制」中删除），最多 3 个
 *  - optional 备选卡片（用户在「定制」中按需添加），最多 20 个
 *
 * 此处仅声明菜单 id，标题/路由/图标/权限均取自 host/menus.php，避免重复维护。
 * 经 HostBootstrap::ensureAdminShortcuts() 以 short=host 登记到接口注册表。
 *
 * @return array{default: list<string>, optional: list<string>}
 */
return [
    // 默认显示卡片：最常用的三个管理入口
    'default' => [
        'menu.superadmin.package',  // 功能安装
        'menu.superadmin.user',     // 用户管理
        'menu.superadmin.audit',    // 日志审计
    ],

    // 备选卡片：其余可从左侧菜单进入的平台功能
    'optional' => [
        'menu.superadmin.review',               // 功能包审核
        'menu.superadmin.uireplace',            // 界面替换
        'menu.superadmin.role',                 // 角色管理
        'menu.superadmin.group',                // 权限组管理
        'menu.superadmin.permission',           // 权限项
        'menu.superadmin.ps',                   // 平台服务总览
        'menu.superadmin.services',             // 平台服务清单
        'menu.superadmin.attach.files',         // 附件管理
        'menu.superadmin.attach.storage',       // 存储配置
        'menu.superadmin.dict',                 // 数据字典
        'menu.superadmin.loginstate',           // 登录状态
        'menu.superadmin.inbox.records',        // 消息投递记录
        'menu.superadmin.inbox.announce',       // 群发公告
        'menu.superadmin.llm.model',            // 模型实例
        'menu.superadmin.llm.log',              // 调用日志
        'menu.superadmin.llm.debug',            // 调试台
        'menu.superadmin.llm.quota',            // 调用方配额
        'menu.superadmin.systeminfo',           // 关于系统
        'menu.superadmin.backup',               // 归档备份
        'menu.superadmin.site',                 // 全局设置
    ],
];
