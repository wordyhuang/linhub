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

        // Lite 档请求尾触发：只登记回调，配置与 DB 连接推迟到请求结束后。
        \linhub\host\services\scheduler\SchedulerTriggerSource::attachLazy(
            static fn () => new \linhub\host\services\scheduler\SchedulerService()
        );
    }
}
