<?php

declare(strict_types=1);

namespace linhub\capability\auth;

use think\facade\Env;

/**
 * UAIS 开放 API 客户端（T0.3 / C1 C2 C3，真实协议对接版）
 *
 * 服务：统一账号识别管理平台（Django + DRF），协议经线上联调确认：
 *
 *  1. 应用凭证：POST {base}/api/v1/open/token，入参 {app_key, app_secret}，
 *     返回 data.access_token（JWT，有效期 7200s）。该 App Token 标识"哪个应用在调用"。
 *  2. 除换取 App Token 外，所有请求都必须在 URL query 携带 ?access_token=<appToken>；
 *     代表用户的请求另加 Header Authorization: Bearer <userToken>。
 *  3. 用户登录：POST /api/v1/auth/login，入参 {username, password}，
 *     返回 data：access_token / refresh_token / user_id / username / nickname /
 *     user_type / tags(string[]) / is_staff / is_superuser。
 *  4. 当前用户：GET /api/v1/auth/me（Bearer）；刷新：POST /api/v1/auth/token/refresh；
 *     注销：POST /api/v1/auth/logout。
 *  5. 响应统一包装 {code:int, message:string, data:*}，code==0 成功；
 *     失败为负数（如 -10002 缺少 Token、-10202 无接口调用权限、-10602 参数错误）。
 *
 * 凭证一律经 Env 读取（支持构造注入以便测试），绝不写死（安全红线）。
 */
class UaisClient
{
    /** App Token 提前刷新余量（秒），避免临界点失效。 */
    private const REFRESH_MARGIN = 60;

    private string $baseUrl;
    private string $appKey;
    private string $appSecret;
    private int $timeout;

    private ?string $cachedAppToken = null;
    private int $appTokenExpiresAt = 0;

    public function __construct(
        ?string $baseUrl = null,
        ?string $appKey = null,
        ?string $appSecret = null,
        ?int $timeout = null,
    ) {
        $this->baseUrl   = rtrim($baseUrl ?? (string) Env::get('UAIS_BASE_URL', ''), '/');
        $this->appKey    = $appKey ?? (string) Env::get('UAIS_APP_KEY', '');
        $this->appSecret = $appSecret ?? (string) Env::get('UAIS_APP_SECRET', '');
        $this->timeout   = $timeout ?? 10;
    }

    /**
     * 获取（必要时刷新）应用 Access Token，内存缓存至过期前。
     *
     * @throws UaisException 凭证缺失、网络错误或服务端拒绝
     */
    public function getAppAccessToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh
            && $this->cachedAppToken !== null
            && time() < $this->appTokenExpiresAt - self::REFRESH_MARGIN
        ) {
            return $this->cachedAppToken;
        }

        if ($this->appKey === '' || $this->appSecret === '') {
            throw new UaisException('UAIS App 凭证未配置（UAIS_APP_KEY / UAIS_APP_SECRET）');
        }

        $envelope = $this->rawRequest(
            'POST',
            '/api/v1/open/token',
            ['app_key' => $this->appKey, 'app_secret' => $this->appSecret],
            [],
            null,
        );

        if ((int) ($envelope['code'] ?? -1) !== 0) {
            throw new UaisException('UAIS App Token 获取失败：' . (string) ($envelope['message'] ?? '未知错误'));
        }

        $token = (string) ($envelope['data']['access_token'] ?? '');
        if ($token === '') {
            throw new UaisException('UAIS App Token 响应缺少 access_token');
        }

        $this->cachedAppToken   = $token;
        $this->appTokenExpiresAt = time() + (int) ($envelope['data']['expires_in'] ?? 7200);
        return $token;
    }

    /**
     * 用户登录（用户名 + 密码），换取用户令牌包。
     *
     * @return array{access_token:string, refresh_token?:string, user_id?:int, username?:string, nickname?:string, tags?:list<string>, is_staff?:bool, is_superuser?:bool}
     * @throws UaisException 网络层错误；账号密码错误以 envelope 形式返回（code<0），由调用方判定
     */
    public function userLogin(string $username, string $password): array
    {
        $envelope = $this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if ((int) ($envelope['code'] ?? -1) !== 0) {
            return $envelope;
        }
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        // 线上 /auth/login 成功响应把用户令牌放在 `token` 字段，且 /open/token 用 `access_token`。
        // 统一映射为 `access_token`，保持下游 AuthService 与文档契约一致。
        if (isset($data['token']) && !isset($data['access_token'])) {
            $data['access_token'] = $data['token'];
        }
        return $data;
    }

    /**
     * 拉取 UAIS 全量用户列表（App Token，GET /api/v1/open/users）。
     *
     * 线上协议实测（2026-10，已为当前 App 授权）：
     *  - envelope code==0，data 形如 {users:list, total:int}；
     *  - page / page_size 参数被服务端忽略，任意取值均返回全量用户（当前 31 行），
     *    故本方法不做客户端翻页，直接以返回 users 为准；
     *  - 行字段：id / uuid / user_type / username / email / phone / nickname /
     *    avatar / department / status(1=启用) / tenant_id / last_login_at /
     *    created_at / tags / company；
     *  - 单用户详情 open/users/<id> 当前仍 -10202 未授权，不能按 id 单点查询。
     *
     * @return list<array<string,mixed>> 全量用户行
     * @throws UaisException 网络错误或服务端拒绝（如 -10202 无接口权限）
     */
    public function listUsers(): array
    {
        $envelope = $this->request('GET', '/api/v1/open/users', [], ['page' => 1, 'page_size' => 500]);
        if ((int) ($envelope['code'] ?? -1) !== 0) {
            throw new UaisException('UAIS 用户列表获取失败：' . (string) ($envelope['message'] ?? '未知错误'));
        }
        $users = is_array($envelope['data'] ?? null) ? ($envelope['data']['users'] ?? null) : null;
        return is_array($users) ? array_values($users) : [];
    }

    /**
     * 获取当前用户资料（需用户 Token）。
     *
     * @return array{id?:int, user_id?:int, username?:string, nickname?:string, name?:string, tags?:list<mixed>}
     */
    public function userMe(string $userToken): array
    {
        $envelope = $this->request('GET', '/api/v1/auth/me', [], [], $userToken);
        return (int) ($envelope['code'] ?? -1) === 0 && is_array($envelope['data'] ?? null)
            ? $envelope['data']
            : [];
    }

    /**
     * 刷新用户令牌（需 refresh_token）。
     *
     * @return array{access_token?:string, refresh_token?:string}
     */
    public function refreshUserToken(string $refreshToken): array
    {
        $envelope = $this->request('POST', '/api/v1/auth/token/refresh', [
            'refresh_token' => $refreshToken,
        ]);
        return (int) ($envelope['code'] ?? -1) === 0 && is_array($envelope['data'] ?? null)
            ? $envelope['data']
            : [];
    }

    /**
     * 注销用户令牌。
     */
    public function userLogout(string $userToken): void
    {
        $this->request('POST', '/api/v1/auth/logout', [], [], $userToken);
    }

    /**
     * 校验用户 Token（POST /api/v1/open/token/verify）。
     *
     * @return array{valid:bool, user_id:int|null, tenant_id:int|null, user_type:string|null}
     */
    public function verifyUserToken(string $userToken): array
    {
        $envelope = $this->request('POST', '/api/v1/open/token/verify', ['token' => $userToken]);

        $d     = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $valid = (int) ($envelope['code'] ?? -1) === 0 && (bool) ($d['valid'] ?? false);
        return [
            'valid'     => $valid,
            'user_id'   => $valid ? ($d['user_id'] ?? null) : null,
            'tenant_id' => $valid ? ($d['tenant_id'] ?? null) : null,
            'user_type' => $valid ? ($d['user_type'] ?? null) : null,
        ];
    }

    /**
     * 发起一个带 App 凭证（自动获取并拼入 query）的 API 请求，返回完整 envelope。
     *
     * @param array<string,mixed> $body      JSON 请求体
     * @param array<string,string> $query    额外 query 参数
     * @param string|null $userToken         用户 Token（Bearer）
     * @return array{code:int, message:string, data:mixed}
     */
    public function request(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        ?string $userToken = null,
    ): array {
        $appToken             = $this->getAppAccessToken();
        $query['access_token'] = $appToken;
        return $this->rawRequest($method, $path, $body, $query, $userToken);
    }

    /**
     * 底层 HTTP：不处理 App Token，直接发请求并解析 envelope。
     *
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @return array{code:int, message:string, data:mixed}
     * @throws UaisException
     */
    private function rawRequest(
        string $method,
        string $path,
        array $body,
        array $query,
        ?string $userToken,
    ): array {
        if ($this->baseUrl === '') {
            throw new UaisException('UAIS_BASE_URL 未配置');
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Content-Type: application/json; charset=utf-8'];
        if ($userToken !== null && $userToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $userToken;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);
        if ($body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new UaisException('UAIS 请求失败：' . $err);
        }

        $decoded = json_decode((string) $resp, true);
        if (!is_array($decoded)) {
            throw new UaisException('UAIS 返回无法解析：' . substr((string) $resp, 0, 200));
        }

        return [
            'code'    => (int) ($decoded['code'] ?? -1),
            'message' => (string) ($decoded['message'] ?? ''),
            'data'    => $decoded['data'] ?? null,
        ];
    }
}
