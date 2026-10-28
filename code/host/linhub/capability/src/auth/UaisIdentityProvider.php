<?php

declare(strict_types=1);

namespace linhub\capability\auth;

use think\facade\Env;
use think\facade\Request;

/**
 * 基于 UAIS 的身份识别实现（T0.3 / C1 C2 C3，真实协议对接版）
 *
 * 从当前 HTTP 请求的 Authorization: Bearer <userToken> 取得用户令牌，
 * 调 GET /api/v1/auth/me 获取用户资料（id / username / nickname / tags），
 * 构造 {@see Identity}。宿主据此判定是否超级管理员（tag = UAIS_ADMIN_TAG）。
 *
 * 认证链路依赖 {@see UaisClient}：
 *   - UaisClient 自动以 AppKey + AppSecret 换取并缓存应用 Access Token；
 *   - 用户令牌经 Bearer 头传递。
 *
 * 失败语义：
 *   - 请求无 Bearer 令牌、令牌无效/过期、用户不存在 → resolve() 返回 null（未认证）；
 *   - 网络不通、响应无法解析等基础设施错误抛 {@see UaisException}，
 *     不伪装成"未认证"，避免掩盖真实故障。
 */
class UaisIdentityProvider implements IdentityProvider
{
    private string $adminTag;
    private UaisClient $client;

    /** 显式指定的用户令牌（非 HTTP 环境或测试时注入）；null 时从当前请求读取。 */
    private ?string $bearerToken;

    public function __construct(
        ?UaisClient $client = null,
        ?string $bearerToken = null,
        ?string $adminTag = null,
    ) {
        $this->client      = $client ?? new UaisClient();
        $this->bearerToken = $bearerToken;
        $this->adminTag    = $adminTag ?? (string) Env::get('UAIS_ADMIN_TAG', 'LinHub SuperAdministrator');
    }

    /**
     * 解析当前请求的身份。
     *
     * @inheritDoc
     */
    public function resolve(): ?Identity
    {
        $token = $this->bearerToken ?? $this->bearerFromRequest();
        if ($token === null || $token === '') {
            return null;
        }
        return $this->resolveByToken($token);
    }

    /**
     * 以一个明确的用户 Access Token 解析身份（供登录服务以请求中携带的令牌解析）。
     */
    public function resolveByToken(string $userToken): ?Identity
    {
        $me = $this->client->userMe($userToken);
        if ($me === []) {
            return null;
        }

        $id = (string) ($me['id'] ?? $me['user_id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = (string) ($me['nickname'] ?? $me['username'] ?? $me['name'] ?? '');
        $tags = array_values(array_map(
            static fn ($t): string => (string) $t,
            (array) ($me['tags'] ?? []),
        ));

        return new Identity($id, $name, $tags);
    }

    /**
     * 判定某身份是否超级管理员（用户 tag 含 UAIS_ADMIN_TAG）。
     * 供 T0.3 AC 与 T5.2 / T7.1 复用。
     */
    public function isSuperAdmin(?Identity $identity): bool
    {
        return $identity !== null && $identity->hasTag($this->adminTag);
    }

    /**
     * 从当前请求头读取 Bearer Token。CLI / 无该头时返回 null。
     */
    private function bearerFromRequest(): ?string
    {
        $header = (string) Request::header('authorization', '');
        if ($header === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m) !== 1) {
            return null;
        }
        return $m[1];
    }
}
