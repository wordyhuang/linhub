<?php
// 功能包声明文件 - 林枢演示功能包
return [
    'name'        => 'lgg/linhub-guide',
    'short'       => 'linhub-guide',
    'title'       => '林枢演示功能包',
    'version'     => '0.1.0',
    'type'        => 'app',
    'description' => '林枢演示功能包（平台脚手架生成）',
    // 'access'   => 'admin', // 默认中后台；如需免登录公开请填 public 并配置 access_routes

    'dependencies'=> [],
    'platform'    => [
        'host' => '>=0.1.0',
        'php'  => '>=8.1',
    ],

    'resources'   => [
        'database' => [['table' => 'lg_linhub-guide_record', 'type' => 'create']],
        'files'    => ['public/lgg/linhub-guide/'],
    ],
    'migrations'  => 'migrations',

    'services'    => [
        'provides' => [],
        'requires' => ['logging', 'config'],
    ],
    'permissions' => 'permissions.php',
    'menus'       => 'menus.php',
    'subscribes'  => [],
];