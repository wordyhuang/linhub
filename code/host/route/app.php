<?php
// +----------------------------------------------------------------------
// | 根级路由：面向终端用户的平台公共入口（不带多应用前缀）
// +----------------------------------------------------------------------
// 说明：think-multi-app 下，路径首段不是应用名（admin/index）时，MultiApp
//       会跳过应用解析并回落到根路由。这里用完整控制器类名把公开入口挂到
//       index 应用的控制器，使调用方拿到的 URL 稳定为：
//         /attachment/download?token=xxx
//         /inbox/unreadCount、/inbox/list、/inbox/read、/inbox/readAll
//       /index/* 带前缀的等价入口（app/index/route/app.php）保持不变。
// +----------------------------------------------------------------------

use think\facade\Route;

Route::get('attachment/download', '\app\index\controller\Attach@download');

Route::get('inbox/unreadCount', '\app\index\controller\Notice@unreadCount');
Route::get('inbox/list', '\app\index\controller\Notice@list');
Route::post('inbox/read', '\app\index\controller\Notice@read');
Route::post('inbox/readAll', '\app\index\controller\Notice@readAll');
