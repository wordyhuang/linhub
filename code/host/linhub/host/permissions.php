<?php

declare(strict_types=1);

/**
 * 平台权限点声明（与功能包 permissions.php 同构，A4/J7/J7-b/J8 约定）。
 *
 * 经 HostBootstrap::adminPermissionDefs() include 返回，结构与功能包 permissions.php 完全一致：
 *  - code       权限编码「产品:资源:操作」（J8，三段非空）
 *  - title      显示名
 *  - description 权限说明
 *  - group      权限分组（platform=平台权限）
 *  - roles      限定可见角色；空数组=不限角色（J7-b）
 *
 * @return list<array<string, mixed>>
 */
return [
    ['code' => 'host:package:review',  'title' => '功能包审核',   'description' => '允许审核功能包接入申请（权限、菜单、数据表与迁移脚本等），审核通过后功能包方可安装或升级接入。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:role:view',       'title' => '查看角色',     'description' => '允许浏览角色列表与角色配置详情。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:role:manage',     'title' => '管理角色',     'description' => '允许创建、编辑、删除角色及其权限分配。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:group:view',      'title' => '查看权限组',   'description' => '允许浏览权限组列表及其成员配置。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:group:manage',    'title' => '管理权限组',   'description' => '允许创建、编辑、删除权限组并调整组成员。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:user:view',       'title' => '查看用户',     'description' => '允许浏览平台用户列表与基本信息。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:user:manage',     'title' => '管理用户',     'description' => '允许创建、编辑、删除用户并分配角色权限。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:permission:view', 'title' => '浏览权限项',   'description' => '允许查看各功能包与平台登记的权限点清单。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:audit:view',      'title' => '查看日志审计', 'description' => '允许浏览平台操作日志与审计轨迹。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:backup:view',     'title' => '查看归档备份', 'description' => '允许浏览平台归档与备份记录。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:backup:manage',   'title' => '管理归档备份', 'description' => '允许创建、恢复、删除平台归档与备份。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:uireplace:view',  'title' => '查看界面替换', 'description' => '允许浏览各功能包提交的界面替换候选。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:uireplace:manage','title' => '选择界面替换', 'description' => '允许选择启用或停用某功能包的界面替换。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:schema:view',     'title' => '查看 Schema 看板','description' => '允许浏览数据库结构与迁移差异看板。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:services:view',   'title' => '查看平台服务', 'description' => '允许浏览平台注册的服务与能力清单。', 'group' => 'platform', 'roles' => []],

    // 平台公共服务（PS-1 / AT / DD / LS / IM）
    ['code' => 'host:platform:view',      'title' => '平台服务总览',   'description' => '允许查看附件、字典、登录态、站内消息四服务的用量总览看板。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:attachment:view',    'title' => '查看附件托管',   'description' => '允许浏览附件文件管理器、存储配置与清理记录。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:attachment:manage',  'title' => '管理附件托管',   'description' => '允许上传默认图片、保存托管参数、删除附件、测试驱动与手动触发清理。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:dict:view',          'title' => '查看数据字典',   'description' => '允许跨功能包命名空间全局检索数据字典（只读，含私有项）。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:dict:manage',        'title' => '管理数据字典',   'description' => '允许修改平台数据字典条目（值/备注），按值类型差异化编辑。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:loginstate:view',    'title' => '查看登录状态',   'description' => '允许查看当前请求的登录态只读快照。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:message:view',       'title' => '查看站内消息',   'description' => '允许浏览消息投递记录与收件人解析快照。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:message:send',       'title' => '发送系统公告',   'description' => '允许向用户/角色/用户组群发平台系统公告。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:translation:manage', 'title' => '管理翻译词条',   'description' => '允许跨功能包命名空间检索、新增、修改、删除多语言翻译词条，并查看缺失对照（修改记录 owner_pkg=host）。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:recyclebin:view',    'title' => '查看回收站',     'description' => '允许浏览全部功能包与宿主命名空间的回收站托管条目（含孤儿态）与用量统计。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:recyclebin:manage',  'title' => '管理回收站',     'description' => '允许跨功能包命名空间查看条目载荷、延长保留期、彻底删除（单条/批量）并手动触发到期清理。', 'group' => 'platform', 'roles' => []],

    // 平台公共服务：AP 审批服务（审批中心）
    ['code' => 'host:approval:view',      'title' => '查看审批单据',   'description' => '允许浏览本人待办、本人已办与审批单详情，并在详情页处理待办。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:approval:manage',    'title' => '管理审批单据',   'description' => '允许跨功能包查看全部审批单据与审批服务用量统计，并撤回在办单据。', 'group' => 'platform', 'roles' => []],

    // 全局设置（站点名称 / 网络备案号 / logo）
    ['code' => 'host:setting:manage',     'title' => '全局设置',       'description' => '允许维护平台全局设置：站点名称、网络备案号（ICP）与站点 logo。', 'group' => 'platform', 'roles' => []],

    // 关于系统（平台环境信息只读看板）
    ['code' => 'host:systeminfo:view',    'title' => '查看关于系统',   'description' => '允许查看平台运行环境信息：平台版本、PHP 版本/SAPI/ini、服务器 OS/主机名、磁盘与内存概况、关键扩展检测。', 'group' => 'platform', 'roles' => []],

    // LLM 网关（LLM-1）
    ['code' => 'host:llm:model:view',     'title' => '查看 LLM 模型实例', 'description' => '允许浏览 LLM 网关已注册的模型实例列表与详情（base_url/api_key 不展示）。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:llm:model:manage',   'title' => '管理 LLM 模型实例', 'description' => '允许新建、编辑、禁用 LLM 模型实例。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:llm:log:view',      'title' => '查看 LLM 调用日志','description' => '允许浏览 LLM 网关调用日志、今日用量统计与单次调用的 prompt/completion 全文。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:llm:debug:use',     'title' => '使用 LLM 调试台',  'description' => '允许在后台调用真实 LLM 模型进行连通性与效果验证（caller 固定为 admin.debug）。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:llm:quota:view',    'title' => '查看 caller 配额', 'description' => '允许浏览 LLM 网关按 (caller, model_code) 粒度配置的日 token 上限。', 'group' => 'platform', 'roles' => []],
    ['code' => 'host:llm:quota:manage',  'title' => '管理 caller 配额','description' => '允许新建、编辑、删除 LLM caller 的日 token 配额。', 'group' => 'platform', 'roles' => []],
];
