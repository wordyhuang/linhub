<?php
// +----------------------------------------------------------------------
// | admin 应用视图路径覆盖
// | admin 已独立为 linhub/admin 包，物理目录不在 app/ 下；
// | think-view 默认按 basePath(即 app/)+appName/view 定位，无法命中迁移后目录，
// | 这里显式把 view_path 指到本包内的 view 目录。
// +----------------------------------------------------------------------
return [
    'view_path' => __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR,
];