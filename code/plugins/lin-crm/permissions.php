<?php

declare(strict_types=1);

/**
 * 林客 CRM 功能包 · 权限点声明（28 权限点）
 *
 * 平台标准格式：结构化行 ['code'=>..., 'title'=>..., 'group'=>..., 'description'=>...]，
 * 参照 04 功能包开发指引 §6.1 与 code/plugins/lin-trade/permissions.php。
 * 注意：早期版本用纯 code 字符串数组，PermissionRegistry 取不到 'code' 键导致 26 个
 * 权限点全部未登记，进而菜单 perms 交集过滤后全部不可见——必须用结构化行格式。
 * code 三段式「产品:资源:操作」；roles 省略 = 对所有角色可见可授权（J7-b）。
 * 角色数据行权限四档默认：管理员/销售总监=全公司，销售员=仅本人，内勤=本部门，财务=本部门（合同/回款对象）。
 */
return [
    // 客户管理（6）
    ['code' => 'lin-crm:customer:view',     'title' => '查看客户',     'group' => '客户管理', 'description' => '查看客户列表、公海与客户 360 详情'],
    ['code' => 'lin-crm:customer:create',   'title' => '新建客户',     'group' => '客户管理', 'description' => '新建客户档案'],
    ['code' => 'lin-crm:customer:edit',     'title' => '编辑客户',     'group' => '客户管理', 'description' => '编辑客户资料/发起客户合并'],
    ['code' => 'lin-crm:customer:delete',   'title' => '删除客户',     'group' => '客户管理', 'description' => '删除客户（软删进回收站）'],
    ['code' => 'lin-crm:customer:transfer', 'title' => '转移客户',     'group' => '客户管理', 'description' => '转移客户归属（管理员/销售总监）'],
    ['code' => 'lin-crm:customer:release',  'title' => '收回客户',     'group' => '客户管理', 'description' => '收回客户回公海（管理员/销售总监）'],

    // 线索管理（4）
    ['code' => 'lin-crm:lead:view',    'title' => '查看线索', 'group' => '线索管理', 'description' => '查看线索池与线索列表'],
    ['code' => 'lin-crm:lead:create',  'title' => '录入线索', 'group' => '线索管理', 'description' => '录入新线索'],
    ['code' => 'lin-crm:lead:assign',  'title' => '分配线索', 'group' => '线索管理', 'description' => '分配线索给销售（管理员/销售总监/内勤）'],
    ['code' => 'lin-crm:lead:convert', 'title' => '线索转化', 'group' => '线索管理', 'description' => '线索转化为客户/商机'],

    // 商机管理（5）
    ['code' => 'lin-crm:opportunity:view',   'title' => '查看商机',   'group' => '商机管理', 'description' => '查看商机列表与漏斗看板'],
    ['code' => 'lin-crm:opportunity:create', 'title' => '新建商机',   'group' => '商机管理', 'description' => '新建商机'],
    ['code' => 'lin-crm:opportunity:edit',   'title' => '编辑商机',   'group' => '商机管理', 'description' => '编辑商机/阶段推进/阶段回退'],
    ['code' => 'lin-crm:opportunity:won',    'title' => '商机赢单',   'group' => '商机管理', 'description' => '赢单落终态'],
    ['code' => 'lin-crm:opportunity:lost',   'title' => '商机输单',   'group' => '商机管理', 'description' => '输单落终态'],

    // 合同管理（4）
    ['code' => 'lin-crm:contract:view',   'title' => '查看合同',   'group' => '合同管理', 'description' => '查看合同列表与详情'],
    ['code' => 'lin-crm:contract:create', 'title' => '新建合同',   'group' => '合同管理', 'description' => '新建合同'],
    ['code' => 'lin-crm:contract:audit',  'title' => '合同审批',   'group' => '合同管理', 'description' => '合同审批（通过/驳回）'],
    ['code' => 'lin-crm:contract:change', 'title' => '合同变更',   'group' => '合同管理', 'description' => '生效后合同改额/延期（留痕）'],

    // 回款管理（2）
    ['code' => 'lin-crm:receivable:plan',     'title' => '维护回款计划', 'group' => '回款管理', 'description' => '维护合同回款计划（财务）'],
    ['code' => 'lin-crm:receivable:register', 'title' => '回款登记核销', 'group' => '回款管理', 'description' => '回款登记与核销（财务）'],

    // 跟单管理（2）
    ['code' => 'lin-crm:order:view',   'title' => '查看跟单', 'group' => '跟单管理', 'description' => '查看跟单看板（林销销售订单履约进度，只读聚合）'],
    ['code' => 'lin-crm:order:follow', 'title' => '新增跟单记录', 'group' => '跟单管理', 'description' => '对跟单订单新增跟进/异常记录'],

    // 跟进与其他（5）
    ['code' => 'lin-crm:followup:create', 'title' => '新增跟进记录',   'group' => '其他', 'description' => '为客户/线索/商机新增跟进记录'],
    ['code' => 'lin-crm:phone:view_full', 'title' => '查看完整手机号', 'group' => '其他', 'description' => '默认脱敏，解权查看完整手机号并落审计日志'],
    ['code' => 'lin-crm:report:view',     'title' => '查看报表',       'group' => '其他', 'description' => '查看报表中心与工作台统计卡片'],
    ['code' => 'lin-crm:report:export',   'title' => '报表导出',       'group' => '其他', 'description' => '报表 Excel 导出（行为落审计日志）'],
    ['code' => 'lin-crm:config:manage',   'title' => 'CRM 系统设置',   'group' => '其他', 'description' => '提醒规则/公海规则/字典管理/回收站/审计日志'],
];
