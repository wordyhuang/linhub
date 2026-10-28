<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    // 平台中后台 admin 已独立为 linhub/admin 包，物理目录不在 app/ 下，
    // 通过 app_map 闭包把应用目录指到 linhub/admin/src（controller/route/view 均基于该目录）。
    'app_map'          => [
        'admin' => function ($app) {
            $app->http->path(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'linhub' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'src');
            return 'admin';
        },
    ],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
];
