<?php

declare(strict_types=1);

namespace linhub\host\package;

/**
 * 功能包脚手架（T1.4 / O2 / E2）
 *
 * 提供"新建功能包"能力：按《功能包协议规范》目录结构生成初始源码与 manifest 模板，
 * 生成的包可直接被平台校验并安装到模板状态（E1/E2 边界）。
 */
final class PackageScaffold
{
    /**
     * 生成一个功能包骨架。
     *
     * @param string $short    包短名（小写字母/数字`-`，用于命名空间与表前缀）
     * @param string $title    人类可读名称
     * @param string $rootDir  生成根目录（在其下创建 lgg-pkg-<short>/）
     * @param string $version  初始版本，默认 0.1.0
     * @param string $type     包类型：app|lib，默认 app
     * @return string 生成的包目录绝对路径
     * @throws \RuntimeException 短名非法或目录已存在
     */
    public static function scaffold(
        string $short,
        string $title,
        string $rootDir,
        string $version = '0.1.0',
        string $type = 'app',
    ): string {
        if ($short === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $short)) {
            throw new \RuntimeException("非法包短名：{$short}（需小写字母/数字/`-`）");
        }
        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \RuntimeException("非法版本：{$version}（需语义化 主.次.修订）");
        }

        $dir = rtrim($rootDir, '/\\') . "/lgg-pkg-{$short}";
        if (is_dir($dir)) {
            throw new \RuntimeException("目标包目录已存在：{$dir}");
        }

        $pascal = self::pascal($short);
        $namespace = "Lg\\Pack\\{$pascal}";

        $dirs = [
            "$dir/src/Controller",
            "$dir/src/Service",
            "$dir/src/Model",
            "$dir/src/Events",
            "$dir/src/Subscribers",
            "$dir/src/view",
            "$dir/migrations",
            "$dir/resources/assets",
            "$dir/resources/templates",
            "$dir/resources/config",
            "$dir/translations",
        ];
        foreach ($dirs as $d) {
            mkdir($d, 0775, true);
        }

        self::write("$dir/manifest.php", self::manifest($short, $title, $version, $type));
        self::write("$dir/composer.json", self::composer($namespace));
        self::write("$dir/src/Package.php", self::package($namespace));
        self::write("$dir/src/Service/DemoService.php", self::demoService($namespace));
        self::write("$dir/permissions.php", self::permissions($short));
        self::write("$dir/menus.php", self::menus($short));
        self::write("$dir/shortcuts.php", self::shortcuts($short));
        self::write("$dir/route.php", self::route($short, $namespace));
        self::write("$dir/src/Controller/Index.php", self::indexController($namespace));
        self::write("$dir/src/view/index.html", self::indexView($short, $title));
        self::write("$dir/translations/zh-CN.php", self::translationSeed('zh-CN', $short));
        self::write("$dir/translations/en-US.php", self::translationSeed('en-US', $short));
        self::write("$dir/README.md", "# {$title}\n\n{$short} 功能包：平台骨架生成。\n");

        return $dir;
    }

    private static function write(string $file, string $content): void
    {
        file_put_contents($file, $content);
    }

    private static function pascal(string $short): string
    {
        $parts = array_map('ucfirst', explode('-', $short));
        return implode('', $parts);
    }

    private static function manifest(string $short, string $title, string $version, string $type): string
    {
        return <<<PHP
<?php
// 功能包声明文件 - {$title}
return [
    'name'        => 'lgg/{$short}',
    'short'       => '{$short}',
    'title'       => '{$title}',
    'version'     => '{$version}',
    'type'        => '{$type}',
    'description' => '{$title}（平台脚手架生成）',
    // 'access'   => 'admin', // 默认中后台；如需免登录公开请填 public 并配置 access_routes

    'dependencies'=> [],
    'platform'    => [
        'host' => '>=0.1.0',
        'php'  => '>=8.1',
    ],

    'resources'   => [
        'database' => [['table' => 'lg_{$short}_record', 'type' => 'create']],
        'files'    => ['public/lgg/{$short}/'],
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
PHP;
    }

    private static function composer(string $namespace): string
    {
        return json_encode(
            [
                'name' => 'lgg/demo',
                'type' => 'project',
                'autoload' => ['psr-4' => ["{$namespace}\\" => 'src/']],
                'extra' => ['lgg' => true],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    private static function package(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use think\Service;

/**
 * 功能包入口类：可选，提供 install/upgrade/rollback 生命周期钩子。
 */
class Package extends Service
{
    public function install(): void
    {
    }

    public function upgrade(string \$fromVersion): void
    {
    }

    public function rollback(): void
    {
    }
}
PHP;
    }

    private static function demoService(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\Service;

/**
 * 示例服务：注册为宿主服务供其他包/宿主消费（N3/M）。
 */
class DemoService
{
    public function greet(string \$name): string
    {
        return "问候，{\$name}";
    }
}
PHP;
    }

    private static function permissions(string $short): string
    {
        return <<<PHP
<?php
// 权限点声明（A4/J7/J8）
return [
    [
        'code'  => '{$short}:{$short}:view',
        'title' => '查看{$short}',
        'roles' => [],
        'group' => '{$short}',
    ],
];
PHP;
    }

    private static function menus(string $short): string
    {
        return <<<PHP
<?php
// 菜单声明（A5/J6）
return [
    [
        'id'     => '{$short}.index',
        'parent' => null,
        'title'  => '{$short}首页',
        'route'  => '{$short}.index',
        'icon'   => '',
        'order'  => 500,
        'perms'  => [],
    ],
];
PHP;
    }

    /**
     * 首页快捷入口声明（与功能包 shortcuts.php 同构）。
     *
     * default = 安装后默认展示到工作区首页的卡片（每包 ≤3，用户可定制删除）；
     * optional = 备选卡片（每包 ≤20，用户在「定制」抽屉按需添加）。仅声明菜单 id。
     */
    private static function shortcuts(string $short): string
    {
        return <<<PHP
<?php
// 首页快捷入口声明：default ≤3，optional ≤20，卡片以菜单 id 引用
return [
    'default'  => ['{$short}.index'],
    'optional' => [],
];
PHP;
    }

    /**
     * 路由声明：把菜单 route（{$short}.index）映射为可访问的 URL 路径。
     *
     * 与菜单/默认首页控制器联动，保证新建包打开首页不再是 404。
     * 路由写裸路径即可：PackageRouteLoader 会在开启 package_route_force_admin 时自动包 /admin/ 前缀。
     */
    private static function route(string $short, string $namespace): string
    {
        return <<<PHP
<?php
// 路由声明：将菜单 route 对齐为可访问 URL 路径（T2/J6）
use think\facade\Route;
use {$namespace}\Controller\Index;

Route::get('{$short}', Index::class . '@index');
Route::get('{$short}/index', Index::class . '@index');
PHP;
    }

    /**
     * 默认首页控制器：功能包菜单入口的处理器。
     *
     * 使用直接读取 src/view 下 HTML 渲染（与现行功能包规范一致，如 lin-trade TradeController::render），
     * 避免依赖 Think 模板引擎的 view_path 解析（宿主未为包控制器配置 view_path）。
     */
    private static function indexController(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\Controller;

use think\Response;

/**
 * 默认首页控制器：功能包菜单入口。
 */
class Index
{
    public function index(): Response
    {
        \$file = dirname(__DIR__) . '/view/index.html';
        if (!is_file(\$file)) {
            return response('page not found', 404);
        }
        return response((string) file_get_contents(\$file))->contentType('text/html; charset=utf-8');
    }
}
PHP;
    }

    /**
     * 翻译种子示例（翻译服务 TR）：translations/<lang>.php 随包安装导入、升级全量覆盖、卸载清除。
     * 文件名为 BCP-47 语言简码；key 约定点号命名（模块.对象.字段.值）。
     */
    private static function translationSeed(string $lang, string $short): string
    {
        $samples = $lang === 'zh-CN'
            ? [
                "'{$short}.demo.title'     => '示例标题',",
                "'{$short}.demo.count'     => '共 {count} 条',",
                "'{$short}.status.enabled' => '启用',",
                "'{$short}.status.disabled'=> '停用',",
            ]
            : [
                "'{$short}.demo.title'     => 'Sample title',",
                "'{$short}.demo.count'     => '{count} items in total',",
                "'{$short}.status.enabled' => 'Enabled',",
                "'{$short}.status.disabled'=> 'Disabled',",
            ];
        $body = implode("\n    ", $samples);
        return <<<PHP
<?php
// 翻译种子（{$lang}）——安装时导入，升级时全量覆盖
return [
    {$body}
];
PHP;
    }

    /** 默认首页视图：纯 HTML 占位页。 */
    private static function indexView(string $short, string $title): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body>
    <h1>{$title}</h1>
    <p>{$short} 功能包首页（平台脚手架骨架），请基于此页面开发业务内容。</p>
</body>
</html>
HTML;
    }
}