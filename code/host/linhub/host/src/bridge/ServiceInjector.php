<?php

declare(strict_types=1);

namespace linhub\host\bridge;

use linhub\host\metrics\RecordingServiceProxy;
use linhub\host\services\llmgateway\BoundLlmGateway;
use linhub\host\services\llmgateway\LlmGateway;
use linhub\host\services\ServiceRegistry;
use linhub\host\manifest\VersionConstraint;
use think\App;

/**
 * 宿主服务注入器（T1.6 / bridge.injector）。
 *
 * 按功能包 manifest.services.requires 解析服务 id 与版本，返回可注入的接口实例。
 * 功能包仅面向契约接口消费；未注册/版本不符时抛异常（M006）。
 * 契约与默认实现的映射来自服务清单 services.php（ServiceRegistry::hostList）。
 */
final class ServiceInjector
{
    /** 契约/实现 class 按需从清单取，见 resolveAll()。 */
    private static ?array $map = null;

    /** id => ['contract' => ..., 'impl' => ...] 懒加载自服务清单。 */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        self::$map = [];
        foreach (ServiceRegistry::hostList() as $id => $entry) {
            $impl = $entry['impl'] ?? null;
            self::$map[$id] = [
                'contract' => (string) ($entry['contract'] ?? ''),
                'impl'     => $impl !== null && $impl !== '' ? (string) $impl : null,
            ];
        }
        foreach (ServiceRegistry::packageProvides() as $id => $entry) {
            $class = (string) ($entry['class'] ?? '');
            if ($class === '') {
                continue;
            }
            self::$map[$id] = [
                'contract' => $class,
                'impl'     => $class,
            ];
        }
        return self::$map;
    }

    public function __construct(private readonly App $app)
    {
    }

    /**
     * 解析注入 manifest.services.requires 声明的服务实例。
     *
     * @param array $requires id 列表，如 ['logging','config'] 或 [{'logging':'^1.0'}]
     * @param string|null $pkgId 功能包 id（用于 llmgateway 焊死 caller=pkg.<id>）
     * @return array<string, object>
     * @throws \RuntimeException 服务未注册或版本不满足
     */
    public function resolveAll(array $requires, ?string $pkgId = null): array
    {
        $map     = self::map();
        $hostIds = ServiceRegistry::hostList();
        $out     = [];
        foreach ($requires as $entry) {
            if (is_string($entry)) {
                $id = $entry;
                $constraint = '*';
            } else {
                $id = (string) key($entry);
                $constraint = (string) reset($entry);
            }

            // 校验存在 + 版本（host + package 事实源，不依赖内存注册时机）
            $current = ServiceRegistry::versionOf($id);
            if ($current === null) {
                throw new \RuntimeException("服务未注册：{$id}");
            }
            if ($constraint !== '*' && $constraint !== ''
                && !VersionConstraint::matches($current, $constraint)) {
                throw new \RuntimeException("服务 {$id} 版本不满足约束：{$constraint}（当前 {$current}）");
            }

            $class = $map[$id]['impl'] ?? null;
            if ($class === null) {
                $pkg = ServiceRegistry::packageProvides()[$id] ?? null;
                $class = is_array($pkg) && ($pkg['class'] ?? '') !== '' ? (string) $pkg['class'] : null;
            }
            if ($class === null) {
                throw new \RuntimeException("暂无宿主内置实现，无法注入服务：{$id}");
            }
            $instance = $this->app->make($class);

            // llmgateway 特殊处理：用 BoundLlmGateway 焊死 caller=pkg.<id>，
            // 让功能包不需要自己传 caller；显式传的 caller 会被覆盖。
            if ($id === 'llmgateway' && $instance instanceof LlmGateway) {
                $boundCaller = $pkgId !== null && $pkgId !== ''
                    ? 'pkg.' . $pkgId
                    : 'pkg.unknown';
                $instance = new BoundLlmGateway($instance, $boundCaller);
            }

            /** @var object $instance 统一套调用统计代理（host / package 服务均计数） */
            $instance = RecordingServiceProxy::wrap(
                $instance,
                $id,
                isset($hostIds[$id]) ? 'host' : 'package',
                $pkgId
            );

            $out[$id] = $instance;
        }
        return $out;
    }

    /** 判断某 id 是否为宿主内置可注入。 */
    public static function has(string $id): bool
    {
        return isset(self::map()[$id]);
    }
}