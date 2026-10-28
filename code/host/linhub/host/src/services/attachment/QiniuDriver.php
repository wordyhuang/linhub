<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

use think\facade\Env;

/**
 * 七牛云对象存储驱动（AT 存储工厂可选实现）。
 *
 * 七牛 SDK（qiniu/php-sdk）不是宿主的硬依赖：未安装时本类仍可实例化（便于配置页展示状态），
 * 但 ping() 返回未就绪、实际读写抛异常。密钥全部来自环境变量，绝不入库入源码。
 *
 * 需要的环境变量：
 *  - ATTACH_QINIU_AK / ATTACH_QINIU_SK  访问密钥
 *  - ATTACH_QINIU_BUCKET               空间名
 *  - ATTACH_QINIU_DOMAIN               绑定的访问域名（含协议，如 https://cdn.example.com）
 *  - ATTACH_QINIU_PRIVATE             是否私有空间（1/0），私有空间下载 URL 会带签名
 */
final class QiniuDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $domain;
    private bool $private;

    public function __construct(
        string $accessKey = '',
        string $secretKey = '',
        string $bucket = '',
        string $domain = '',
        bool $private = false,
    ) {
        $this->accessKey = $accessKey !== '' ? $accessKey : (string) Env::get('ATTACH_QINIU_AK', '');
        $this->secretKey = $secretKey !== '' ? $secretKey : (string) Env::get('ATTACH_QINIU_SK', '');
        $this->bucket    = $bucket !== '' ? $bucket : (string) Env::get('ATTACH_QINIU_BUCKET', '');
        $this->domain    = rtrim($domain !== '' ? $domain : (string) Env::get('ATTACH_QINIU_DOMAIN', ''), '/');
        $this->private   = $private || (string) Env::get('ATTACH_QINIU_PRIVATE', '0') === '1';
    }

    public function name(): string
    {
        return 'qiniu';
    }

    /** 七牛 SDK 是否可用。 */
    public static function sdkAvailable(): bool
    {
        return class_exists(\Qiniu\Auth::class)
            && class_exists(\Qiniu\Storage\UploadManager::class)
            && class_exists(\Qiniu\Storage\BucketManager::class);
    }

    /** 凭证是否已配置（只返回布尔，绝不回显密钥）。 */
    public function configured(): bool
    {
        return $this->accessKey !== '' && $this->secretKey !== '' && $this->bucket !== '' && $this->domain !== '';
    }

    public function put(string $key, string $content): string
    {
        $this->assertReady();
        $auth    = new \Qiniu\Auth($this->accessKey, $this->secretKey);
        $token   = $auth->uploadToken($this->bucket);
        $manager = new \Qiniu\Storage\UploadManager();
        // put 直接接受二进制内容（内部落临时文件上传）
        [, $error] = $manager->put($token, $key, $content);
        if ($error !== null) {
            throw new \RuntimeException('七牛附件上传失败：' . json_encode($error, JSON_UNESCAPED_UNICODE));
        }
        return $key;
    }

    public function get(string $key): string
    {
        $this->assertReady();
        $url = $this->signedUrl($key);
        $content = @file_get_contents($url);
        if ($content === false) {
            throw new \RuntimeException("七牛附件读取失败：{$key}");
        }
        return $content;
    }

    public function exists(string $key): bool
    {
        if (!self::sdkAvailable() || !$this->configured()) {
            return false;
        }
        try {
            $auth    = new \Qiniu\Auth($this->accessKey, $this->secretKey);
            $manager = new \Qiniu\Storage\BucketManager($auth, new \Qiniu\Config());
            [, $error] = $manager->stat($this->bucket, $key);
            return $error === null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function delete(string $key): void
    {
        if (!self::sdkAvailable() || !$this->configured()) {
            return;
        }
        $auth    = new \Qiniu\Auth($this->accessKey, $this->secretKey);
        $manager = new \Qiniu\Storage\BucketManager($auth, new \Qiniu\Config());
        $manager->delete($this->bucket, $key);
    }

    public function url(string $key, bool $public = true): string
    {
        // 外框架内统一走平台下载控制器（便于权限裁决与缺图兜底）；直链场景可用 signedUrl()
        return '/attachment/download?token=' . rawurlencode(basename($key));
    }

    /** 七牛侧真实访问 URL（私有空间带签名）。 */
    public function signedUrl(string $key): string
    {
        $base = $this->domain . '/' . ltrim($key, '/');
        if ($this->private && self::sdkAvailable() && $this->configured()) {
            $auth = new \Qiniu\Auth($this->accessKey, $this->secretKey);
            return $auth->privateDownloadUrl($base);
        }
        return $base;
    }

    public function ping(): array
    {
        if (!self::sdkAvailable()) {
            return ['ok' => false, 'message' => '未安装 qiniu/php-sdk，七牛驱动不可用（composer require qiniu/php-sdk）'];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'message' => '七牛配置不完整（AK/SK/Bucket/Domain 至少一项缺失）'];
        }
        // 轻量自检：列出空间 1 个对象验证凭证与连通性
        try {
            $auth    = new \Qiniu\Auth($this->accessKey, $this->secretKey);
            $manager = new \Qiniu\Storage\BucketManager($auth, new \Qiniu\Config());
            [, $error] = $manager->listFiles($this->bucket, '', '', 1, '');
            if ($error !== null) {
                return ['ok' => false, 'message' => '七牛连通性校验失败：' . json_encode($error, JSON_UNESCAPED_UNICODE)];
            }
            return ['ok' => true, 'message' => '七牛配置有效，空间可访问：' . $this->bucket];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '七牛连通性校验异常：' . $e->getMessage()];
        }
    }

    private function assertReady(): void
    {
        if (!self::sdkAvailable()) {
            throw new \RuntimeException('未安装 qiniu/php-sdk，无法使用七牛存储驱动');
        }
        if (!$this->configured()) {
            throw new \RuntimeException('七牛存储配置不完整，请检查 ATTACH_QINIU_* 环境变量');
        }
    }
}
