<?php
// +----------------------------------------------------------------------
// | admin 应用路由（中后台，全部需登录；登录页/登录提交由应用中间件白名单放行）
// +----------------------------------------------------------------------
use think\facade\Route;

// —— 登录 / 登出 ——
Route::get('auth/login', 'auth/login');
Route::post('auth/login', 'auth/doLogin');
Route::post('auth/doLogin', 'auth/doLogin');
Route::get('auth/logout', 'auth/logout');

// —— 工作台（中后台首页） ——
// completeMatch() 强制完全匹配：route_complete_match 默认关闭，否则 home/dashboard 会前缀匹配 home/worker 等
Route::get('/', 'home/dashboard');
Route::get('home', 'home/dashboard')->completeMatch();
Route::get('home/dashboard', 'home/dashboard')->completeMatch();

// —— 按角色类型的独立 home 页（登录后按角色动态加载） ——
Route::get('home/worker', 'home/worker')->completeMatch();
Route::get('home/functionalAdmin', 'home/functionalAdmin')->completeMatch();
Route::get('home/systemAdmin', 'home/systemAdmin')->completeMatch();
Route::get('home/superAdmin', 'home/superAdmin')->completeMatch();
Route::post('home/customSave', 'home/customSave');
Route::post('home/profileSave', 'home/profileSave');
Route::get('home/myPerms', 'home/myPerms');

// —— 超管专属「功能包管理」（方案 B） ——
Route::get('package/index', 'package/index');
Route::get('package/view', 'package/view');
Route::get('package/rows', 'package/rows');
Route::get('package/detail', 'package/detail');
Route::get('package/dashboard', 'package/dashboard');
Route::get('package/dashboardStats', 'package/dashboardStats');
Route::get('package/removePreview', 'package/removePreview');
Route::post('package/create', 'package/create');
Route::post('package/pack', 'package/pack');
Route::post('package/install', 'package/install');
Route::post('package/upgrade', 'package/upgrade');
Route::post('package/rollback', 'package/rollback');
Route::post('package/enable', 'package/enable');
Route::post('package/disable', 'package/disable');
Route::post('package/remove', 'package/remove');
Route::post('package/removeStaged', 'package/removeStaged');

// —— 超管专属「功能包审核」（需求 F：审核通过后方可安装/升级接入） ——
Route::get('package_review/index', 'package_review/index');
Route::get('package_review/rows', 'package_review/rows');
Route::get('package_review/detail', 'package_review/detail');
Route::post('package_review/approve', 'package_review/approve');
Route::post('package_review/reject', 'package_review/reject');
Route::post('package_review/batchApprove', 'package_review/batchApprove');
Route::post('package_review/batchReject', 'package_review/batchReject');

// —— 超管专属「平台 / workdesktop 版本检测与自升级」 ——
Route::get('update/versions', 'update/versions');
Route::post('update/run', 'update/run');
Route::get('update/rollbackOptions', 'update/rollbackOptions');
Route::post('update/rollback', 'update/rollback');

// —— 权限项浏览（G3，只读） ——
Route::get('permission/index', 'permission/index');
Route::get('permission/rows', 'permission/rows');
Route::get('permission/options', 'permission/options');

// —— 角色管理（G1） ——
Route::get('role/index', 'role/index');
Route::get('role/rows', 'role/rows');
Route::get('role/members', 'role/members');
Route::get('role/options', 'role/options');
Route::post('role/save', 'role/save');
Route::post('role/delete', 'role/delete');

// —— 权限组管理（G2） ——
Route::get('group/index', 'group/index');
Route::get('group/rows', 'group/rows');
Route::get('group/options', 'group/options');
Route::post('group/save', 'group/save');
Route::post('group/perms', 'group/perms');
Route::get('group/users', 'group/users');
Route::post('group/usersSave', 'group/usersSave');
Route::post('group/delete', 'group/delete');

// —— 用户管理（G4，本地映射） ——
Route::get('user/index', 'user/index');
Route::get('user/rows', 'user/rows');
Route::get('user/options', 'user/options');
Route::post('user/save', 'user/save');
Route::post('user/assign', 'user/assign');
Route::post('user/delete', 'user/delete');
Route::post('user/sync', 'user/sync');

// —— 日志审计（G5，只读检索） ——
Route::get('audit/index', 'audit/index');

// —— 数据归档备份（G6） ——
Route::get('backup/index', 'backup/index');
Route::get('backup/policy', 'backup/policy');
Route::post('backup/policy', 'backup/policy');
Route::post('backup/run', 'backup/run');
Route::post('backup/restore', 'backup/restore');

// —— 界面替换管理（G7） ——
Route::get('uireplace/index', 'uireplace/index');
Route::post('uireplace/choose', 'uireplace/choose');

// —— Schema 差异看板（G8，只读） ——
Route::get('schema/diff', 'schema/diff');

// —— 服务治理看板（需求 E / G3，只读） ——
Route::get('services/dashboard', 'service_governance/dashboard');
Route::get('services/overview', 'service_governance/overview');

// —— 平台公共服务：PS-1 总览看板（只读） ——
Route::get('platform_service/index', 'platform_service/index');
Route::get('platform_service/stats', 'platform_service/stats');

// —— 平台能力：关于系统（只读环境信息） ——
Route::get('system_info/index', 'system_info/index');
Route::get('system_info/info', 'system_info/info');

// —— 平台公共服务：AT-9 默认图片 / AT-10 文件管理 / AT-11 存储配置 / AT-12 清理记录 ——
Route::get('attach_admin/fallback', 'attach_admin/fallback');
Route::get('attach_admin/fallbackState', 'attach_admin/fallbackState');
Route::post('attach_admin/fallbackUpload', 'attach_admin/fallbackUpload');
Route::post('attach_admin/fallbackReset', 'attach_admin/fallbackReset');
Route::post('attach_admin/settingsSave', 'attach_admin/settingsSave');
Route::get('attach_admin/files', 'attach_admin/files');
Route::get('attach_admin/rows', 'attach_admin/rows');
Route::post('attach_admin/upload', 'attach_admin/upload');
Route::post('attach_admin/delete', 'attach_admin/delete');
Route::get('attach_admin/storage', 'attach_admin/storage');
Route::get('attach_admin/config', 'attach_admin/config');
Route::post('attach_admin/driverTest', 'attach_admin/driverTest');
Route::get('attach_admin/clean', 'attach_admin/clean');
Route::get('attach_admin/cleanRows', 'attach_admin/cleanRows');
Route::post('attach_admin/cleanRun', 'attach_admin/cleanRun');

// —— 平台公共服务：DD-9 数据字典全局只读检索 ——
Route::get('dict_admin/index', 'dict_admin/index');
Route::get('dict_admin/rows', 'dict_admin/rows');
Route::get('dict_admin/detail', 'dict_admin/detail');
Route::get('dict_admin/options', 'dict_admin/options');
Route::post('dict_admin/save', 'dict_admin/save');

// —— 平台公共服务：TR 翻译管理（检索 / 编辑 / 缺失对照） ——
Route::get('translation_admin/index', 'translation_admin/index');
Route::get('translation_admin/rows', 'translation_admin/rows');
Route::get('translation_admin/options', 'translation_admin/options');
Route::get('translation_admin/missing', 'translation_admin/missing');
Route::post('translation_admin/save', 'translation_admin/save');
Route::post('translation_admin/delete', 'translation_admin/delete');

// —— 平台公共服务：RB 回收站（检索 / 载荷预览 / 保留期延长 / 彻底删除 / 到期清理） ——
Route::get('recyclebin_admin/index', 'recyclebin_admin/index');
Route::get('recyclebin_admin/rows', 'recyclebin_admin/rows');
Route::get('recyclebin_admin/preview', 'recyclebin_admin/preview');
Route::get('recyclebin_admin/export', 'recyclebin_admin/export');
Route::post('recyclebin_admin/extend', 'recyclebin_admin/extend');
Route::post('recyclebin_admin/purge', 'recyclebin_admin/purge');
Route::post('recyclebin_admin/purgeBatch', 'recyclebin_admin/purgeBatch');
Route::post('recyclebin_admin/cleanExpired', 'recyclebin_admin/cleanExpired');

// —— 平台公共服务：AP 审批中心（我的待办 / 我的已办 / 全部单据 / 详情 / 审批动作） ——
Route::get('approval_admin/index', 'approval_admin/index');
Route::get('approval_admin/todo', 'approval_admin/todo');
Route::get('approval_admin/done', 'approval_admin/done');
Route::get('approval_admin/detail', 'approval_admin/detail');
Route::get('approval_admin/rows', 'approval_admin/rows');
Route::get('approval_admin/pendingRows', 'approval_admin/pendingRows');
Route::get('approval_admin/handledRows', 'approval_admin/handledRows');
Route::get('approval_admin/detailData', 'approval_admin/detailData');
Route::post('approval_admin/approve', 'approval_admin/approve');
Route::post('approval_admin/reject', 'approval_admin/reject');
Route::post('approval_admin/cancel', 'approval_admin/cancel');

// —— 平台公共服务：LS-6 登录态查看（只读） ——
Route::get('login_state_admin/index', 'login_state_admin/index');
Route::get('login_state_admin/snapshot', 'login_state_admin/snapshot');

// —— 全局设置（站点名称 / 网络备案号 / logo，仅超管） ——
Route::get('site_admin/index', 'site_admin/index');
Route::get('site_admin/state', 'site_admin/state');
Route::post('site_admin/save', 'site_admin/save');
Route::post('site_admin/logoUpload', 'site_admin/logoUpload');
Route::post('site_admin/logoReset', 'site_admin/logoReset');

// —— 平台公共服务：IM-14 投递记录（只读） / IM-15 群发公告 ——
Route::get('message_admin/records', 'message_admin/records');
Route::get('message_admin/rows', 'message_admin/rows');
Route::get('message_admin/detail', 'message_admin/detail');
Route::get('message_admin/options', 'message_admin/options');
Route::get('message_admin/announce', 'message_admin/announce');
Route::get('message_admin/targets', 'message_admin/targets');
Route::post('message_admin/send', 'message_admin/send');

// —— LLM 网关（T9 模型 CRUD / T10 调用日志 / T11 调试台 / T12 caller 配额） ——
Route::get('llm_model_admin/index', 'llm_model_admin/index');
Route::get('llm_model_admin/rows', 'llm_model_admin/rows');
Route::get('llm_model_admin/detail', 'llm_model_admin/detail');
Route::post('llm_model_admin/save', 'llm_model_admin/save');
Route::post('llm_model_admin/delete', 'llm_model_admin/delete');

Route::get('llm_log_admin/index', 'llm_log_admin/index');
Route::get('llm_log_admin/rows', 'llm_log_admin/rows');
Route::get('llm_log_admin/detail', 'llm_log_admin/detail');
Route::get('llm_log_admin/stats', 'llm_log_admin/stats');

Route::get('llm_debug_admin/index', 'llm_debug_admin/index');
Route::get('llm_debug_admin/models', 'llm_debug_admin/models');
Route::post('llm_debug_admin/chat', 'llm_debug_admin/chat');
Route::post('llm_debug_admin/embed', 'llm_debug_admin/embed');

Route::get('llm_quota_admin/index', 'llm_quota_admin/index');
Route::get('llm_quota_admin/rows', 'llm_quota_admin/rows');
Route::get('llm_quota_admin/modelOptions', 'llm_quota_admin/modelOptions');
Route::post('llm_quota_admin/save', 'llm_quota_admin/save');
Route::post('llm_quota_admin/delete', 'llm_quota_admin/delete');
