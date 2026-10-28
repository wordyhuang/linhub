<?php

declare(strict_types=1);

namespace linhub\host\communication\services;

use linhub\host\metrics\RecordingServiceProxy;
use linhub\host\services\ServiceRegistry;
use think\App;

/**
 * 服务注册表消费门面（T6.4 / N3 / M）
 *
 * 功能包将其能力注册为宿主服务（registerPackage）；他功能包经宿主注册表查找
 * 并调用，不直接跨包耦合。本类提供"经宿主查找 + 实例化 + 方法调用"的统一入口。
 */
final class ServiceGateway
{
    private function __construct()
    {
    }

    /**
     * 解析某已注册服务的实现类名。
     *
     * @throws \RuntimeException 服务未注册
     */
    public static function resolveServiceClass(string $id): string
    {
        $entry = ServiceRegistry::get($id);
        if ($entry === null) {
            throw new \RuntimeException("服务未注册：{$id}");
        }
        return $entry['class'];
    }

    /** 判断某服务是否由功能包注册（package 源）。 */
    public static function isPackageService(string $id): bool
    {
        $entry = ServiceRegistry::get($id);
        return $entry !== null && $entry['source'] === 'package';
    }

    /** 登记某个 id（无则返回 false；本方法不覆盖已注册服务，与注册表规则一致）。 */
    public static function registerIfAbsent(string $id, string $class, string $version = '^1.0'): bool
    {
        if (ServiceRegistry::get($id) !== null) {
            return false;
        }
        ServiceRegistry::registerPackage($id, $class, $version);
        return true;
    }

    /**
     * 获取服务实例（优先容器装配，退化到无参构造）。
     *
     * @throws \RuntimeException 服务未注册
     */
    public static function resolveService(App $app, string $id): object
    {
        $class = self::resolveServiceClass($id);
        try {
            $service = $app->make($class);
        } catch (\Throwable) {
            $service = new $class();
        }
        $entry  = ServiceRegistry::get($id);
        $source = (string) ($entry['source'] ?? 'package');
        return RecordingServiceProxy::wrap($service, $id, $source);
    }

    /**
     * 经宿主调用他包服务的方法（无跨包直接耦合）。
     *
     * @param string $id     服务 id（他包注册于宿主的服务）
     * @param string $method 方法名
     * @param array $args    方法参数
     */
    public static function invoke(App $app, string $id, string $method, array $args = []): mixed
    {
        $service = self::resolveService($app, $id);
        return $service->{$method}(...$args);
    }
}