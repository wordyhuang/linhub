<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\capability\auth\UaisClient;
use linhub\host\Version;
use think\Config;

/**
 * 关于系统（平台能力只读看板）—— admin 应用，只读
 *
 *  - GET /admin/system_info/index   看板空壳（前端拉 collect 渲染）
 *  - GET /admin/system_info/info     系统环境信息 JSON
 *
 * 只读约束：本页不承载任何写操作；仅暴露「非敏感」的服务器/PHP/平台版本/资源概况。
 * 敏感凭证（api_key/db 密码/token 等）一律不进入输出。
 */
class SystemInfo extends AdminController
{
    /** 看板页。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('system_info/index');
    }

    /** JSON：系统环境信息。 */
    public function info()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk($this->collect());
    }

    /**
     * 采集系统环境信息（私有方法，TDD 反射直接测）。
     *
     * @return array{
     *   platform: array{version:string},
     *   php: array{version:string, sapi:string, loaded_config:string, timezone:string, upload_max_filesize:string, post_max_size:string, memory_limit:string},
     *   server: array{os:string, hostname:string, software:string},
     *   system: array{disk_total:int, disk_free:int, memory_usage:int, memory_peak:int},
     *   extensions: array<string,bool>,
     *   database: array{driver:string, host:string, port:string, database:string, charset:string, version:string}
     * }
     */
    private function collect(): array
    {
        // 磁盘：取平台运行根目录所在分区，cli/web 下都能拿到
        $diskRoot = DIRECTORY_SEPARATOR;
        $diskTotal = @disk_total_space($diskRoot);
        $diskFree  = @disk_free_space($diskRoot);

        $extensions = [];
        foreach (['json', 'mbstring', 'pdo', 'openssl', 'curl', 'fileinfo', 'gd', 'zip', 'phar', 'zlib'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        // database：仅暴露非敏感配置，尝试 SELECT VERSION() 获取版本信息
        $config = \app('config');
        $defaultConnection = $config->get('database.default');
        $dbConfig = $config->get("database.connections.{$defaultConnection}");
        $version = '';
        // 仅在 pdo_mysql 可用且配置完整时尝试查询版本
        if (extension_loaded('pdo_mysql') && !empty($dbConfig['hostname']) && !empty($dbConfig['database'])) {
            try {
                $dsn = "mysql:host={$dbConfig['hostname']};port={$dbConfig['hostport']};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
                $res = $pdo->query('SELECT VERSION()');
                if ($res) {
                    $row = $res->fetchColumn();
                    if ($row !== false) {
                        $version = (string) $row;
                    }
                }
            } catch (\Throwable) {
                // 连接异常不影响其他采集，仅返回空串
            }
        }

        return [
            'platform' => [
                'version' => Version::current(),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'loaded_config' => (string) php_ini_loaded_file(),
                'scanned_config' => (string) php_ini_scanned_files(),
                'timezone' => (string) date_default_timezone_get(),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
            ],
            'server' => [
                'os' => (string) PHP_OS,
                'hostname' => (string) gethostname(),
                'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
                'interface' => (string) php_sapi_name(),
            ],
            'system' => [
                'disk_total' => $diskTotal === false ? 0 : (int) $diskTotal,
                'disk_free' => $diskFree === false ? 0 : (int) $diskFree,
                'memory_usage' => memory_get_usage(false),
                'memory_peak' => memory_get_peak_usage(false),
            ],
            'extensions' => $extensions,
            'database' => [
                'driver' => (string) ($dbConfig['type'] ?? env('DB_DRIVER', 'mysql')),
                'host' => (string) ($dbConfig['hostname'] ?? env('DB_HOST', '127.0.0.1')),
                'port' => (string) ($dbConfig['hostport'] ?? env('DB_PORT', '3306')),
                'database' => (string) ($dbConfig['database'] ?? env('DB_NAME', '')),
                'charset' => (string) ($dbConfig['charset'] ?? env('DB_CHARSET', 'utf8mb4')),
                'version' => $version,
            ],
            'uais' => $this->collectUais(),
        ];
    }

    /**
     * 采集 UAIS 统一身份认证信息（只读、非敏感）。
     *
     * 只暴露 base_url / 凭证是否已配置 / 管理员 tag 约定 / 连通性状态；
     * 绝不输出 app_key、app_secret、access_token 等敏感凭证明文（安全红线）。
     *
     * @return array{base_url:string, app_key_configured:bool, app_secret_configured:bool, admin_tag:string, status:string, error:string}
     */
    private function collectUais(): array
    {
        $baseUrl = (string) env('UAIS_BASE_URL', '');
        $hasKey   = (string) env('UAIS_APP_KEY', '') !== '';
        $hasSecret = (string) env('UAIS_APP_SECRET', '') !== '';
        $adminTag = (string) env('UAIS_ADMIN_TAG', '');

        $status = '未配置';
        $error  = '';
        if ($baseUrl !== '' && $hasKey && $hasSecret) {
            try {
                // 尝试换取（必要时刷新）App Token，仅校验连通性，不对外返回值
                (new UaisClient())->getAppAccessToken();
                $status = '已连接';
            } catch (\Throwable $e) {
                $status = '连接失败';
                $error  = $e->getMessage();
            }
        }

        return [
            'base_url' => $baseUrl,
            'app_key_configured' => $hasKey,
            'app_secret_configured' => $hasSecret,
            'admin_tag' => $adminTag,
            'status' => $status,
            'error' => $error,
        ];
    }
}
