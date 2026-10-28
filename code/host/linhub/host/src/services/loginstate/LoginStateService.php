<?php

declare(strict_types=1);

namespace linhub\host\services\loginstate;

use linhub\capability\auth\Identity;
use linhub\host\support\HostBootstrap;

/**
 * 登录状态服务实现（LS-1 ~ LS-6）。
 *
 * 只读出口：经 HostBootstrap 统一解析当前身份（内存态 / session 恢复 / 演示账号），
 * 未登录返回游客态而不抛异常，供功能包与 GUI 无差别消费。
 */
final class LoginStateService implements LoginState
{
    private ?HostBootstrap $bootstrap;

    /** 注入为 null 时惰性自建，满足 ServiceInjector 的无参友好构造。 */
    public function __construct(?HostBootstrap $bootstrap = null)
    {
        $this->bootstrap = $bootstrap;
    }

    public function snapshot(): array
    {
        $bootstrap = $this->bootstrap();
        $identity = $bootstrap->currentUser();
        if ($identity === null) {
            return [
                'is_login'        => false,
                'guest'           => true,
                'user_id'         => '',
                'username'        => '',
                'display_name'    => '',
                'last_login_at'   => '',
                'is_super_admin'  => false,
                'tags'            => [],
                'roles'           => [],
            ];
        }

        $auth = HostBootstrap::authService();

        // 最后登录时间取本地用户映射（先内部 id 后 ref_id 匹配），无映射/从未登录给空串
        $users = $bootstrap->rbacUsers();
        $local = $users->get($identity->id) ?? $users->findByRefId($identity->id);
        $lastLoginAt = $local !== null ? (string) ($local['last_login_at'] ?? '') : '';

        return [
            'is_login'        => true,
            'guest'           => false,
            'user_id'         => $identity->id,
            'username'        => $identity->id,
            'display_name'    => $identity->name !== '' ? $identity->name : $identity->id,
            'last_login_at'   => $lastLoginAt,
            'is_super_admin'  => $auth->isSuperAdmin($identity),
            'tags'            => array_values(array_map('strval', $identity->tags)),
            'roles'           => array_values(array_map('strval', $bootstrap->roleTypesFor($identity, $auth))),
        ];
    }

    public function isLogin(): bool
    {
        return $this->snapshot()['is_login'];
    }

    public function userId(): string
    {
        return $this->snapshot()['user_id'];
    }

    public function tags(): array
    {
        return $this->snapshot()['tags'];
    }

    private function bootstrap(): HostBootstrap
    {
        return $this->bootstrap ??= new HostBootstrap();
    }
}
