<?php

declare(strict_types=1);

namespace linhub\host\rbac;

use linhub\capability\auth\Identity;
use linhub\capability\auth\IdentityProvider;
use linhub\capability\auth\UaisClient;
use linhub\capability\auth\UaisIdentityProvider;
use think\facade\Env;

/**
 * 统一登录验证服务（T5.1 / B1 / C1 C2 C3，真实协议对接版）
 *
 * 职责：
 *  - 用户名密码登录（{@see self::loginWithPassword()}）：经 UAIS 换取用户令牌，
 *    建立会话上下文，返回 {@see Identity}（含 tags）；
 *  - 以请求携带的用户令牌解析身份（{@see self::login()}）；
 *  - 平台管理准入：仅超级管理员（tag = UAIS_ADMIN_TAG）可入；
 *  - 注销：通知 UAIS 失效令牌并清空会话。
 *
 * 凭证不写死，UAIS 配置一律经 Env 读取；UaisClient / Provider 支持注入以便测试。
 */
final class AuthService
{
    /** 会话存储文件（JSON）。 */
    private JsonFileStore $sessionStore;

    private IdentityProvider $provider;
    private UaisClient $client;

    /** 超级管理员 tag 判定依据。 */
    private string $adminTag;

    /** 当前会话身份（内存态）。 */
    private ?Identity $current = null;

    /** 当前会话用户访问令牌（代表用户调用 API 时使用）。 */
    private string $userAccessToken = '';

    /** 当前会话用户刷新令牌。 */
    private string $userRefreshToken = '';

    /** 最近一次失败的服务端消息（登录失败原因等）。 */
    private string $lastError = '';

    public function __construct(
        ?IdentityProvider $provider = null,
        ?string $sessionFile = null,
        ?string $adminTag = null,
        ?UaisClient $client = null,
    ) {
        $this->adminTag = $adminTag
            ?? (string) Env::get('UAIS_ADMIN_TAG', 'LinHub SuperAdministrator');
        $this->client = $client ?? new UaisClient();
        $this->provider = $provider
            ?? new UaisIdentityProvider($this->client, null, $this->adminTag);
        $this->sessionStore = new JsonFileStore(
            $sessionFile ?? sys_get_temp_dir() . '/linhub_session.json',
        );
    }

    /**
     * 用户名密码登录：调 UAIS 换取用户令牌并建立会话。
     *
     * @return Identity|null 成功返回身份；账号密码错误/用户禁用等返回 null，
     *                       原因见 {@see self::lastError()}
     */
    public function loginWithPassword(string $username, string $password): ?Identity
    {
        $this->lastError = '';
        $data = $this->client->userLogin($username, $password);

        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            // 失败时 UaisClient 返回的是响应 envelope（code/message）
            $this->lastError = (string) ($data['message'] ?? '登录失败');
            return null;
        }

        $this->userAccessToken  = $accessToken;
        $this->userRefreshToken = (string) ($data['refresh_token'] ?? '');

        $tags = array_values(array_map(
            static fn ($t): string => (string) $t,
            (array) ($data['tags'] ?? []),
        ));
        $identity = new Identity(
            (string) ($data['user_id'] ?? ''),
            (string) ($data['nickname'] ?? $data['username'] ?? ''),
            $tags,
        );

        $this->establish($identity);
        return $identity;
    }

    /**
     * 以用户 Access Token 解析身份（令牌无效/未认证返回 null）。
     *
     * 注意：本方法只解析、不建立会话；确认准入后需调 {@see self::establish()}。
     */
    public function login(string $credential): ?Identity
    {
        if ($credential === '') {
            return null;
        }
        if ($this->provider instanceof UaisIdentityProvider) {
            return $this->provider->resolveByToken($credential);
        }
        return $this->provider->resolve();
    }

    /**
     * 从当前请求上下文解析身份（Authorization: Bearer …，无则 null）。
     */
    public function resolveFromRequest(): ?Identity
    {
        return $this->provider->resolve();
    }

    /** 建立会话上下文（成功登录后调用）。 */
    public function establish(Identity $identity): void
    {
        $this->current = $identity;
        $payload = [
            'id'    => $identity->id,
            'name'  => $identity->name,
            'tags'  => array_values($identity->tags),
        ];
        if ($this->userAccessToken !== '') {
            $payload['access_token']  = $this->userAccessToken;
            $payload['refresh_token'] = $this->userRefreshToken;
        }
        $this->sessionStore->write($payload);
    }

    /** 注销：通知 UAIS 令牌失效并清空会话。 */
    public function logout(): void
    {
        if ($this->userAccessToken !== '') {
            try {
                $this->client->userLogout($this->userAccessToken);
            } catch (\Throwable $e) {
                // 远端失效失败不应阻断本地登出；保留现场由审计/日志另行追踪
            }
        }
        $this->current           = null;
        $this->userAccessToken   = '';
        $this->userRefreshToken  = '';
        $this->sessionStore->write([]);
    }

    /** 当前会话上下文身份。 */
    public function currentIdentity(): ?Identity
    {
        return $this->current;
    }

    /** 当前会话用户 Access Token（供代表用户调用宿主/开放 API）。 */
    public function userAccessToken(): string
    {
        return $this->userAccessToken;
    }

    /** 当前会话用户 Refresh Token。 */
    public function userRefreshToken(): string
    {
        return $this->userRefreshToken;
    }

    /** 最近一次失败原因（服务端原文消息）。 */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /** 判定某身份是否超级管理员（同 UAIS provider 判定，service 层视图）。 */
    public function isSuperAdmin(?Identity $identity): bool
    {
        return $identity !== null && $identity->hasTag($this->adminTag);
    }

    /**
     * 平台管理准入守卫：仅超级管理员可入（T5.1 / T7.1）。
     */
    public function canAccessPlatform(?Identity $identity): bool
    {
        return $this->isSuperAdmin($identity);
    }

    /** 注入"模拟身份"用于验证（会话上下文同步切换）。 */
    public function useMockIdentity(?Identity $identity): void
    {
        $this->current = $identity;
    }
}
