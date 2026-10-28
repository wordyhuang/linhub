<?php
declare (strict_types = 1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // 装配已启用功能包的路由与命名空间自动加载（A-router）。
        (new \linhub\host\integration\PackageRouteLoader($this->app))->boot();
    }
}
