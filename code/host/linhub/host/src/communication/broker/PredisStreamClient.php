<?php

declare(strict_types=1);

namespace linhub\host\communication\broker;

use Predis\Client;

/**
 * 基于 Predis 的 Redis Stream 客户端真实实现（T6.3 / RedisStreamBroker 生产级 client）
 *
 * 从 .env 读取 REDIS_HOST / REDIS_PORT / REDIS_PASS（未注入配置时也可显式传入参数）。
 * 实现 RedisStreamClient 接口的 xadd / createGroup，供 RedisStreamBroker 注入后
 * publish 真实写入 Redis Stream、subscribe 真实创建消费组。
 *
 * 依赖已通过 composer 安装 predis/predis ^3.6；composer 触发时需用 PHP 8.2 运行时
 * （本机 PHP 8.2 路径 D:\BtSoft\php\82\php.exe）。
 */
final class PredisStreamClient implements RedisStreamClient
{
    private Client $client;

    /** 消费组名，createGroup 幂等重试沿用同一组名。 */
    private const DEFAULT_GROUP = 'lg-consumers';

    public function __construct(?string $host = null, ?int $port = null, ?string $pass = null)
    {
        $params = [
            'scheme' => 'tcp',
            'host'   => $host ?? self::env('REDIS_HOST', '127.0.0.1'),
            'port'   => (int) ($port ?? self::env('REDIS_PORT', '6379')),
        ];
        $auth = $pass ?? self::env('REDIS_PASS', '');
        if ($auth !== '') {
            $params['password'] = $auth;
        }
        $this->client = new Client($params);
        $this->client->connect();
    }

    public function xadd(string $stream, array $fields): string
    {
        // Predis 3.6 XADD 类型化方法：xadd($key, array $fields)，ID 默认 *
        $id = $this->client->xadd($stream, $fields);
        return (string) $id;
    }

    public function createGroup(string $stream, string $group): void
    {
        // XGROUP CREATE <stream> <group> $ MKSTREAM；幂等：组已存在时忽略异常。
        try {
            $this->client->executeRaw(['XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM']);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage() ?? '');
            $busy = strpos($msg, 'busygroup') !== false
                || strpos($msg, 'already exists') !== false;
            if (!$busy) {
                // 不是"组已存在"（含服务端无 XGROUP 命令）才抛出
                throw $e;
            }
        }
    }

    /** 从 .env 读取配置值（兼容 ThinkPHP .env 语法 KEY = value）。 */
    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        // .../communication/broker → 回溯 5 级到 code/host/，读取宿主根 .env
        $file = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . '.env';
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
                }
            [$k, $val] = explode('=', $line, 2);
            if (trim($k) === $key) {
                return trim($val);
            }
        }
        return $default;
    }
}