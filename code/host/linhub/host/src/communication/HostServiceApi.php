<?php

declare(strict_types=1);

namespace linhub\host\communication;

use linhub\host\bridge\ServiceInjector;
use linhub\host\services\ServiceRegistry;
use think\App;

/**
 * Host Service API（T6.1 / M1 / M2 / M3）
 *
 * 功能包获取宿主服务实例的统一编排入口。消费方式：按 manifest.services.requires
 * 经服务注册表（ServiceRegistry）解析注入，复用 ServiceInjector；本类封装
 * ensureRegistered / getService / inject 便捷入口，作为功能包与宿主服务的统一门面。
 *
 * 宿主服务只读（M12）：功能包仅可消费，不可注册/覆盖，禁止写入 host 源。
 */
final class HostServiceApi
{
    private function __construct()
    {
    }

    /** 确保宿主内置服务已注册进注册表（幂等，可重复调用）。 */
    public static function ensureRegistered(): void
    {
        ServiceRegistry::bootstrap();
    }

    /**
     * 获取单个宿主服务实例。
     *
     * @param string|null $pkgId 功能包 id（llmgateway 会焊死 caller=pkg.<id>）
     * @throws \RuntimeException 服务未注册 / 版本不满足 / 无内置实现
     */
    public static function getService(App $app, string $id, ?string $pkgId = null): object
    {
        self::ensureRegistered();
        $resolved = (new ServiceInjector($app))->resolveAll([$id], $pkgId);
        return $resolved[$id];
    }

    /**
     * 按 manifest.services.requires 批量注入服务实例。
     *
     * @param array $requires id 列表，如 ['logging','config'] 或 [{'logging':'^1.0'}]
     * @param string|null $pkgId 功能包 id（llmgateway 会焊死 caller=pkg.<id>）
     * @return array<string, object>
     */
    public static function inject(App $app, array $requires, ?string $pkgId = null): array
    {
        self::ensureRegistered();
        return (new ServiceInjector($app))->resolveAll($requires, $pkgId);
    }
}