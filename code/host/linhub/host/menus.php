<?php

declare(strict_types=1);

/**
 * 平台管理菜单声明（与功能包 menus.php 同构，A5/J6 约定）。
 *
 * 经 HostBootstrap::adminMenuDefs() include 返回，结构与功能包 menus.php 完全一致：
 *  - id     菜单唯一标识（树挂接引用）
 *  - parent 父菜单 id；null=根级（挂「超管功能」分导台组）
 *  - title  显示名
 *  - route  控制器路径（''=分组容器）；控制器未落地前 valid=false 不显示
 *  - icon   ElementPlus 图标名
 *  - order  同组内排序（升序）
 *  - perms  平台权限点（缺省不限；超管恒全量，普通用户即便有菜单数据也被控制器超管门禁拦截 C2）
 *
 * @return list<array<string, mixed>>
 */
return [

    // 功能包管理分组：功能安装（原功能包管理页）/ 功能包审核 / 界面替换
    ['id' => 'menu.superadmin.pkg',         'parent' => 'menu.superadmin',   'title' => '功能包管理', 'route' => '',                        'icon' => 'Box',           'order' => 10, 'perms' => []],
    ['id' => 'menu.superadmin.package',     'parent' => 'menu.superadmin.pkg','title' => '功能安装',  'route' => '/admin/package/index',    'icon' => 'Download',      'order' => 10, 'perms' => ['host:system:package']],
    ['id' => 'menu.superadmin.pkgdashboard','parent' => 'menu.superadmin.pkg','title' => '功能包看板', 'route' => '/admin/package/dashboard','icon' => 'DataAnalysis',  'order' => 15, 'perms' => ['host:system:package']],
    ['id' => 'menu.superadmin.review',      'parent' => 'menu.superadmin.pkg','title' => '功能包审核', 'route' => '/admin/package_review/index', 'icon' => 'Stamp',       'order' => 20, 'perms' => ['host:package:review']],
    ['id' => 'menu.superadmin.uireplace',   'parent' => 'menu.superadmin.pkg','title' => '界面替换',   'route' => '/admin/uireplace/index',  'icon' => 'CopyDocument',  'order' => 30, 'perms' => ['host:uireplace:view']],
    ['id' => 'menu.superadmin.rbac',        'parent' => 'menu.superadmin',   'title' => '用户与权限', 'route' => '',                        'icon' => 'UserFilled',    'order' => 20, 'perms' => []],
    ['id' => 'menu.superadmin.role',        'parent' => 'menu.superadmin.rbac', 'title' => '角色管理', 'route' => '/admin/role/index',     'icon' => 'UserFilled',    'order' => 10, 'perms' => ['host:role:view']],
    ['id' => 'menu.superadmin.group',       'parent' => 'menu.superadmin.rbac', 'title' => '权限组管理', 'route' => '/admin/group/index',  'icon' => 'Avatar',        'order' => 20, 'perms' => ['host:group:view']],
    ['id' => 'menu.superadmin.user',        'parent' => 'menu.superadmin.rbac', 'title' => '用户管理', 'route' => '/admin/user/index',     'icon' => 'User',          'order' => 30, 'perms' => ['host:user:view']],
    ['id' => 'menu.superadmin.permission',  'parent' => 'menu.superadmin.rbac', 'title' => '权限项',   'route' => '/admin/permission/index', 'icon' => 'Key',       'order' => 40, 'perms' => ['host:permission:view']],
    
    // 平台服务管理：平台公共服务统一收口为一级分组（平台服务总览 / 附件托管 / 数据字典 / 登录状态 / 站内消息）
    ['id' => 'menu.superadmin.platform',        'parent' => 'menu.superadmin',          'title' => '平台服务管理', 'route' => '',                              'icon' => '/brand/mark.svg',        'order' => 30, 'perms' => []],
    ['id' => 'menu.superadmin.ps',              'parent' => 'menu.superadmin.platform', 'title' => '平台服务总览', 'route' => '/admin/PlatformService/index',  'icon' => 'DataBoard',    'order' => 1, 'perms' => ['host:platform:view']],
    ['id' => 'menu.superadmin.services',    'parent' => 'menu.superadmin.platform', 'title' => '平台服务清单', 'route' => '/admin/ServiceGovernance/dashboard', 'icon' => 'Connection',    'order' => 10, 'perms' => ['host:services:view']],
    ['id' => 'menu.superadmin.attach',          'parent' => 'menu.superadmin.platform', 'title' => '附件托管',   'route' => '',                               'icon' => 'Paperclip',    'order' => 20, 'perms' => []],
    ['id' => 'menu.superadmin.attach.files',    'parent' => 'menu.superadmin.attach',   'title' => '附件管理',   'route' => '/admin/AttachAdmin/files',       'icon' => 'Paperclip',    'order' => 10, 'perms' => ['host:attachment:view']],
    ['id' => 'menu.superadmin.attach.fallback', 'parent' => 'menu.superadmin.attach',   'title' => '默认图片',   'route' => '/admin/AttachAdmin/fallback',    'icon' => 'Picture',      'order' => 20, 'perms' => ['host:attachment:manage']],
    ['id' => 'menu.superadmin.attach.storage',  'parent' => 'menu.superadmin.attach',   'title' => '存储配置',   'route' => '/admin/AttachAdmin/storage',     'icon' => 'Files',        'order' => 30, 'perms' => ['host:attachment:view']],
    ['id' => 'menu.superadmin.attach.clean',    'parent' => 'menu.superadmin.attach',   'title' => '清理记录',   'route' => '/admin/AttachAdmin/clean',       'icon' => 'Delete',       'order' => 40, 'perms' => ['host:attachment:view']],
    ['id' => 'menu.superadmin.dict',            'parent' => 'menu.superadmin.platform', 'title' => '数据字典',   'route' => '/admin/DictAdmin/index',          'icon' => 'Collection',   'order' => 30, 'perms' => ['host:dict:view']],
    ['id' => 'menu.superadmin.loginstate',      'parent' => 'menu.superadmin.platform', 'title' => '登录状态',   'route' => '/admin/LoginStateAdmin/index',    'icon' => 'View',         'order' => 40, 'perms' => ['host:loginstate:view']],
    ['id' => 'menu.superadmin.translation',     'parent' => 'menu.superadmin.platform', 'title' => '翻译管理',   'route' => '/admin/translation_admin/index',  'icon' => 'Collection',   'order' => 45, 'perms' => ['host:translation:manage']],
    ['id' => 'menu.superadmin.recyclebin',      'parent' => 'menu.superadmin.platform', 'title' => '回收站',     'route' => '/admin/recyclebin_admin/index',   'icon' => 'DeleteFilled', 'order' => 46, 'perms' => ['host:recyclebin:view']],
    ['id' => 'menu.superadmin.inbox',           'parent' => 'menu.superadmin.platform', 'title' => '站内消息',   'route' => '',                               'icon' => 'ChatDotRound', 'order' => 50, 'perms' => []],
    ['id' => 'menu.superadmin.inbox.records',   'parent' => 'menu.superadmin.inbox',    'title' => '消息投递记录','route' => '/admin/MessageAdmin/records',    'icon' => 'ChatDotRound', 'order' => 10, 'perms' => ['host:message:view']],
    ['id' => 'menu.superadmin.inbox.announce',  'parent' => 'menu.superadmin.inbox',    'title' => '群发公告',   'route' => '/admin/MessageAdmin/announce',    'icon' => 'Promotion',    'order' => 20, 'perms' => ['host:message:send']],

    // LLM 网关：模型管理 / 调用日志 / 调试台 / caller 配额
    ['id' => 'menu.superadmin.llm',                 'parent' => 'menu.superadmin.platform', 'title' => 'LLM 网关',   'route' => '',                               'icon' => 'MagicStick',  'order' => 60, 'perms' => []],
    ['id' => 'menu.superadmin.llm.model',           'parent' => 'menu.superadmin.llm',       'title' => '模型实例',   'route' => '/admin/LlmModelAdmin/index',     'icon' => 'Cpu',         'order' => 10, 'perms' => ['host:llm:model:view']],
    ['id' => 'menu.superadmin.llm.log',             'parent' => 'menu.superadmin.llm',       'title' => '调用日志',   'route' => '/admin/LlmLogAdmin/index',       'icon' => 'Tickets',     'order' => 20, 'perms' => ['host:llm:log:view']],
    ['id' => 'menu.superadmin.llm.debug',           'parent' => 'menu.superadmin.llm',       'title' => '调试台',     'route' => '/admin/LlmDebugAdmin/index',      'icon' => 'SetUp',       'order' => 30, 'perms' => ['host:llm:debug:use']],
    ['id' => 'menu.superadmin.llm.quota',          'parent' => 'menu.superadmin.llm',       'title' => '调用方配额', 'route' => '/admin/LlmQuotaAdmin/index',      'icon' => 'Scaling',     'order' => 40, 'perms' => ['host:llm:quota:view']],

    // 关于系统（平台环境信息只读看板）
    ['id' => 'menu.superadmin.systeminfo',          'parent' => 'menu.superadmin.platform', 'title' => '关于系统',   'route' => '/admin/SystemInfo/index',         'icon' => 'InfoFilled',  'order' => 70, 'perms' => ['host:systeminfo:view']],

    // 数据安全分组：归档备份 / Schema 看板
    ['id' => 'menu.superadmin.datasec',     'parent' => 'menu.superadmin',   'title' => '数据安全',   'route' => '',                        'icon' => 'Umbrella',      'order' => 40, 'perms' => []],
    ['id' => 'menu.superadmin.backup',      'parent' => 'menu.superadmin.datasec', 'title' => '归档备份', 'route' => '/admin/backup/index',   'icon' => 'FolderOpened',  'order' => 10, 'perms' => ['host:backup:view']],
    ['id' => 'menu.superadmin.schema',      'parent' => 'menu.superadmin.datasec', 'title' => 'Schema 看板','route' => '/admin/schema/diff',    'icon' => 'Coin',          'order' => 20, 'perms' => ['host:schema:view']],

    ['id' => 'menu.superadmin.audit',       'parent' => 'menu.superadmin',   'title' => '日志审计',   'route' => '/admin/audit/index',      'icon' => 'Document',      'order' => 100, 'perms' => ['host:audit:view']],
    ['id' => 'menu.superadmin.site',        'parent' => 'menu.superadmin',   'title' => '全局设置',   'route' => '/admin/site_admin/index',   'icon' => 'Setting',       'order' => 110, 'perms' => ['host:setting:manage']],
];
