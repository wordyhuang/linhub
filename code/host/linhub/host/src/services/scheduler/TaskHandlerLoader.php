<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\scheduler\exception\TaskInvalidHandler;

/**
 * tasks.php 处理器表加载器。
 *
 * 约定（需求 5.3 / 技术方案 11 章）：
 *  - 包根（code/plugins/{short}/）下可有 tasks.php，返回「处理器标识 => 实现类 FQCN」数组；
 *  - 处理器标识由包内自定义、全局唯一建议带前缀，长度 ≤ 191；
 *  - 安装期不 class_exists（M016），运行期解析失败判 failed(invalid_handler) 且不自动重试；
 *  - 进程内按包短名缓存，同一请求多次提交不重复 include。
 */
final class TaskHandlerLoader
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** @var array<string, bool> 已注册 PSR-4 前缀 */
    private static array $registered = [];

    /**
     * 读取包内 tasks.php 映射。
     *
     * @return array<string, string>
     * @throws TaskInvalidHandler tasks.php 返回值不是数组
     */
    public function map(string $short): array
    {
        if (isset(self::$cache[$short])) {
            return self::$cache[$short];
        }
        $root = $this->packageRoot($short);
        $this->registerAutoload($short, $root);
        $definition = $root . DIRECTORY_SEPARATOR . 'tasks.php';
        if (!is_file($definition)) {
            return self::$cache[$short] = [];
        }
        $map = (static function (string $file) {
            return include $file;
        })($definition);
        if (!is_array($map)) {
            throw new TaskInvalidHandler('tasks.php 必须返回数组：' . $definition);
        }
        $normalized = [];
        foreach ($map as $key => $class) {
            if (is_string($key) && is_string($class) && $key !== '' && $class !== '') {
                $normalized[$key] = ltrim($class, '\\');
            }
        }
        return self::$cache[$short] = $normalized;
    }

    /**
     * 解析处理器标识为类名；未声明或类不可用抛 TaskInvalidHandler。
     *
     * @throws TaskInvalidHandler
     */
    public function resolve(string $short, string $handler): string
    {
        $map = $this->map($short);
        if (!isset($map[$handler])) {
            throw new TaskInvalidHandler('未在 tasks.php 声明处理器：' . $handler);
        }
        $class = $map[$handler];
        if (!class_exists($class)) {
            throw new TaskInvalidHandler('处理器类不存在：' . $class);
        }
        if (!is_subclass_of($class, TaskHandler::class)) {
            throw new TaskInvalidHandler('处理器未实现 TaskHandler：' . $class);
        }
        return $class;
    }

    /** 包短名对应的目录（不含尾部分隔符）；目录不存在也返回预期路径，由调用方判存在。 */
    public function packageRoot(string $short): string
    {
        return $this->pluginsRoot() . DIRECTORY_SEPARATOR . $short;
    }

    /** 平台插件根：code/host 的上一级 /plugins。 */
    private function pluginsRoot(): string
    {
        if (function_exists('root_path')) {
            $hostRoot = rtrim((string) root_path(), '/\\');
            return dirname($hostRoot) . DIRECTORY_SEPARATOR . 'plugins';
        }
        return dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'plugins';
    }

    /** 兜底注册包内 PSR-4（宿主已注册时跳过，重复调用无副作用）。 */
    private function registerAutoload(string $short, string $root): void
    {
        if (isset(self::$registered[$short]) || !is_dir($root)) {
            return;
        }
        self::$registered[$short] = true;
        $composer = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composer)) {
            return;
        }
        $decoded = json_decode((string) file_get_contents($composer), true);
        $prefixes = (array) ($decoded['autoload']['psr-4'] ?? []);
        if ($prefixes === []) {
            return;
        }
        $map = [];
        foreach ($prefixes as $prefix => $path) {
            $map[(string) $prefix] = $root . DIRECTORY_SEPARATOR . trim((string) $path, '/\\');
        }
        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = substr($class, strlen($prefix));
                $file = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        });
    }
}
