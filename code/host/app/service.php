<?php

use app\AppService;
use think\app\Service as MultiAppService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
return [
    AppService::class,
    // 多应用模式支持（topthink/think-multi-app）：启动 MultiApp 中间件 + 应用化 Url 生成
    MultiAppService::class,
];
