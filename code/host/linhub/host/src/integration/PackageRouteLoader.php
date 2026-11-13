<?php

declare(strict_types=1);

namespace linhub\host\integration;

use linhub\host\lifecycle\LifecycleState;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\services\messaging\MessagingService;
use linhub\host\support\middleware\RequireLogin;
use think\App;
use think\facade\Route;

/**
 * 功能包路由 + 命名空间自动加载（A-router）
 *
 * 依据功能的 composer.json 的 psr-4 声明，把功能包命名空间注册进 PSR-4 自动加载（指向落地包 src/），
 * 并加载已启用功能包的 route.php（route.php 内用 think\facade\Route 声明自定义路由）。
 *
 * 生命周期约定：
 *  - 仅加载 state=enabled 的功能包；disabled 包源码已在隔离区，loadRoot 下不存在即天然失效；
 *  - enable/disable 后下一次请求自动生效（每次请求重新装配）；
 *  - 路由规则用全限定类名（`Lg\Pack\Hello\controller\Hello@index`），走 CallbackDispatch，
 *    依托已注册的 PSR-4 自动加载实例化，与多应用模块层解耦。
 */
final class PackageRouteLoader
{
    /** 进程级防重注册标志（PSR-4 闭包只注册一次）。 */
    private static bool $autoloadRegistered = false;

    /** 已启用的功能包命名空间集合 namespace => 根路径。 */
    private static array $namespaces = [];

    public function __construct(private App $app)
    {
    }

    /** 落地根（code/plugins，与 Package 控制器一致）。 */
    private static function loadRoot(): string
    {
        return dirname(__DIR__, 5) . '/plugins';
    }

    /** 状态文件（code/host/data/plugin-state.json，持久业务数据区）。 */
    private function stateFile(): string
    {
        return dirname(__DIR__, 4) . '/data/plugin-state.json';
    }

    /**
     * 装配已启用功能包的路由与命名空间。每次请求由宿主服务引导调用。
     * 同时汇总各包 manifest.subscribes 订阅声明，注册进共享事件总线（协议 5.3）。
     */
    public function boot(): void
    {
        // 先注册 PSR-4 自动加载闭包，保证 include route.php 时能解析到功能包控制器类。
        self::registerAutoload();
        $records = (new PackageStateStore($this->stateFile()))->read();
        $manifests = [];
        foreach ($records as $rec) {
            if ((string) ($rec['state'] ?? '') !== LifecycleState::ENABLED) {
                continue;
            }
            $short = (string) ($rec['short'] ?? '');
            if ($short === '') {
                continue;
            }
            $root = self::loadRoot() . '/' . $short;

            // 先读 manifest（纯声明数组，无副作用）：路由补前缀按 access 判定，订阅声明一并收集
            $manifestFile = $root . '/manifest.php';
            $manifest = is_file($manifestFile) ? (array) @include $manifestFile : [];
            if ($manifest !== []) {
                $manifests[] = $manifest;
            }

            $this->loadPackage($root, $manifest);
        }
        if ($manifests !== []) {
            MessagingService::subscribeFromManifests($manifests);
        }
    }

    /**
     * 安装完成里程碑（协议 4.5.1 V1.7）：对刚落地的功能包执行即时装配。
     *
     * 安装当次请求内新包尚未经历 boot() 装配，若不补装配，包类无法被自动加载、
     * subscribes 也不在总线上，收不到 installed 广播。此方法幂等（与 boot()
     * 共用同一套静态 PSR-4 映射与总线装配），失败不抛异常、不影响安装结果。
     */
    public static function attach(string $short): void
    {
        $short = trim($short);
        if ($short === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $short)) {
            return;
        }
        $root = self::loadRoot() . '/' . $short;
        if (!is_dir($root)) {
            return;
        }
        try {
            self::registerAutoload();
            self::registerPsr4FromComposer($root);
            $manifestFile = $root . '/manifest.php';
            $manifest = is_file($manifestFile) ? (array) @include $manifestFile : [];
            if ($manifest !== []) {
                MessagingService::subscribeFromManifests([$manifest]);
            }
        } catch (\Throwable) {
            // 即时装配失败不影响安装结果；下次请求 boot() 正常装配
        }
    }

    /** 加载单个功能包：注册 PSR-4 + include route.php。 */
    private function loadPackage(string $root, array $manifest = []): void
    {
        if (!is_dir($root)) {
            return;
        }
        self::registerPsr4FromComposer($root);
        $routeFile = $root . '/route.php';
        if (!is_file($routeFile)) {
            return;
        }

        // 统一登录门禁：access=admin（中后台，默认值）的包由平台中间件统一校验，包内不再自建门禁；
        // access=public（免登录公开，回调/探活等外部端点）的包不挂门禁，外部调用方不带 admin/ 前缀。
        $access  = (string) ($manifest['access'] ?? 'admin');
        $guard   = $access === 'public' ? [] : [RequireLogin::class];

        // 可配置的 /admin/ 前缀强制补齐：开启时仅对 access=admin 的包用 Route::group('admin', ...)
        // 包裹 route.php 统一补前缀（包内写裸路径，如 demo/record）；关闭时为常规模式。
        $forceAdmin = (bool) $this->app->config->get('route.package_route_force_admin', false);
        if ($forceAdmin && $access === 'admin') {
            Route::group('admin', static function () use ($routeFile): void {
                include $routeFile;
            })->middleware($guard);
            return;
        }

        if ($guard !== []) {
            Route::group(static function () use ($routeFile): void {
                include $routeFile;
            })->middleware($guard);
            return;
        }

        include $routeFile;
    }

    /** 从功能包 composer.json 的 psr-4 声明注册命名空间自动加载。 */
    private static function registerPsr4FromComposer(string $root): void
    {
        $composerFile = $root . '/composer.json';
        if (!is_file($composerFile)) {
            return;
        }
        $composer = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($composer)) {
            return;
        }
        $psr4 = (array) ($composer['autoload']['psr-4'] ?? []);
        foreach ($psr4 as $prefix => $path) {
            if (!is_string($prefix) || $prefix === '' || !is_string($path)) {
                continue;
            }
            $base = rtrim($root . '/' . trim($path, '/\\'), '/\\');
            self::$namespaces[trim($prefix, '\\') . '\\'] = $base;
        }
    }

    /** 注册 PSR-4 自动加载闭包（仅首次注册，避免重复注册累积）。 */
    private static function registerAutoload(): void
    {
        if (self::$autoloadRegistered) {
            return;
        }
        self::$autoloadRegistered = true;
        spl_autoload_register(static function (string $class): void {
            foreach (self::$namespaces as $prefix => $base) {
                if (str_starts_with($class, $prefix)) {
                    $rel = substr($class, strlen($prefix));
                    $file = $base . '/' . str_replace('\\', '/', $rel) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                    return;
                }
            }
        });
    }
}