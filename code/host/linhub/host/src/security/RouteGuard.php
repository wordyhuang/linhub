<?php

declare(strict_types=1);

namespace linhub\host\security;

use linhub\capability\auth\Identity;

/**
 * 路由守卫分流（免登录公开）（T7.5 / D4 / C5 / C6）
 *
 * 依据 manifest 的 access（admin/public）构建免登录白名单；
 * 命中白名单端点在"登录中间件之前"放行，并执行二次鉴权：
 *   - sign=signature 要求有效签名；sign=token 要求有效密钥；
 *   - sign=none 仅当路径命中平台"安全探活清单"（GET）时才放行，否则拒绝（M011 越权拦截）。
 * 未命中白名单的端点需登录（走登录 + RBAC），不因公开通道放开。
 * 签名/密钥校验支持注入 callable，便于测试成功/失败分支；内置 HMAC/密钥占位实现。
 */
final class RouteGuard
{
    /** 平台内置安全探活清单（仅 GET）。 */
    public const SAFE_PROBE_ROUTES = ['/healthz', '/ping', '/__probe__'];

    private $signatureVerifier;

    private $tokenVerifier;

    /** 内置校验用默认密钥（占位）。 */
    private string $secret;

    public function __construct(?callable $signatureVerifier = null, ?callable $tokenVerifier = null, string $secret = '')
    {
        $this->signatureVerifier = $signatureVerifier;
        $this->tokenVerifier     = $tokenVerifier;
        $this->secret            = $secret;
    }

    /**
     * 汇总所有 public 包的 access_routes 为免登录白名单。
     *
     * @param array<int, array<string, mixed>> $manifests
     * @return list<array{path: string, target: string, method: string, sign: string}>
     */
    public function buildWhitelist(array $manifests): array
    {
        $out = [];
        foreach ($manifests as $m) {
            if (($m['access'] ?? 'admin') !== 'public') {
                continue;
            }
            $ra     = (array) ($m['access_routes'] ?? []);
            $sign   = (string) ($ra['sign'] ?? '');
            $target = (string) ($m['short'] ?? $m['name'] ?? '');
            foreach ((array) ($ra['routes'] ?? []) as $route) {
                $method = strtoupper(strtok((string) $route, ' '));
                $path   = trim(substr((string) $route, strlen((string) $method)));
                $out[]  = ['path' => $path, 'target' => $target, 'method' => $method, 'sign' => $sign];
            }
        }
        return $out;
    }

    /**
     * 路由分流判定。
     *
     * @param array<int, array<string, mixed>> $manifestList
     */
    public function dispatch(string $path, string $method, array $manifestList, ?Identity $identity = null): GuardDecision
    {
        $method = strtoupper($method);
        $entry  = $this->matchEntry($path, $method, $manifestList);

        // 未命中白名单端点在：需登录（走登录 + RBAC），不因公开通道放开。
        if ($entry === null) {
            return new GuardDecision(false, 'login_required', 'protected');
        }

        $sign = (string) ($entry['sign'] ?? 'none');
        switch ($sign) {
            case 'signature':
                $provided = (string) ($entry['signature'] ?? '');
                $ok       = $this->verifySignature($path, $method, $provided, (string) ($entry['secret'] ?? $this->secret));
                return $ok
                    ? new GuardDecision(true, 'signature_ok', 'signed')
                    : new GuardDecision(false, 'signature_invalid', 'signed');

            case 'token':
                $provided = (string) ($entry['token'] ?? '');
                $ok       = $this->verifyToken($provided, (string) ($entry['secret'] ?? $this->secret));
                return $ok
                    ? new GuardDecision(true, 'token_ok', 'token')
                    : new GuardDecision(false, 'token_invalid', 'token');

            case 'none':
            default:
                // sign=none 仅当命中平台安全探活清单（GET）才放行，否则 M011 越权拦截。
                if ($method === 'GET' && in_array($path, self::SAFE_PROBE_ROUTES, true)) {
                    return new GuardDecision(true, 'probe_allowed', 'none');
                }
                return new GuardDecision(false, 'probe_forbidden', 'none');
        }
    }

    /** 在白名单中精确匹配 路径 + 方法 的条目。 */
    private function matchEntry(string $path, string $method, array $manifestList): ?array
    {
        foreach ($this->buildWhitelist($manifestList) as $e) {
            if ($e['method'] === $method && $e['path'] === $path) {
                return $e;
            }
        }
        return null;
    }

    /** 签名校验：注入 callable 优先，否则内置 HMAC 占位（HMAC-SHA256 over "path|method"）。 */
    public function verifySignature(string $path, string $method, string $provided, ?string $secret = null): bool
    {
        if ($this->signatureVerifier !== null) {
            $fn = $this->signatureVerifier;
            return (bool) $fn($path, $method, $provided, $secret);
        }
        $secret = $secret ?? $this->secret;
        $expect = hash_hmac('sha256', "$path|" . strtoupper($method), $secret);
        return $secret !== '' && hash_equals($expect, $provided);
    }

    /** 密钥校验：注入 callable 优先，否则内置直接比较密钥占位。 */
    public function verifyToken(string $provided, ?string $secret = null): bool
    {
        if ($this->tokenVerifier !== null) {
            $fn = $this->tokenVerifier;
            return (bool) $fn($provided, $secret);
        }
        $secret = $secret ?? $this->secret;
        return $secret !== '' && hash_equals($secret, $provided);
    }
}