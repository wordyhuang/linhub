<?php
// +----------------------------------------------------------------------
// | index 应用路由（面向终端登录用户的公开入口，不挂管理端登录守卫）
// +----------------------------------------------------------------------
use think\facade\Route;

// —— 附件统一下载入口（AT-4/AT-8）：公开附件免鉴权，私有附件服务层裁决 ——
Route::get('attachment/download', 'attach/download');

// -- 外框架通知中心（IM-7~IM-13）：接口内部校验登录态 --
Route::get('inbox/unreadCount', 'notice/unreadCount');
Route::get('inbox/list', 'notice/list');
Route::post('inbox/read', 'notice/read');
Route::post('inbox/readAll', 'notice/readAll');

// -- LLM 网关 SSE 参考代理（轨 B，单轮 user prompt，caller 由 session 推导） --
Route::get('llm/stream', 'llm_stream/stream');
