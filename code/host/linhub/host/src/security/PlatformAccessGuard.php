<?php

declare(strict_types=1);

namespace linhub\host\security;

use linhub\capability\auth\Identity;
use linhub\host\rbac\AuditLog;
use linhub\host\rbac\AuthService;

/**
 * 平台级超管管控（T7.1 / C2 / C3 / C1）
 *
 * 平台管理面身份准入守卫：仅超级管理员可进入平台管理面。
 * 内部持有判定器（AuthService 或 callable），无身份或非超管一律拒绝。
 * 准入调用在共享 AuditLog 上留痕（type=platform_entry）。
 */
final class PlatformAccessGuard
{
    /** 准入判定器：接收 ?Identity 返回是否准入。 */
    private $checker;

    /** 共享审计日志（可空，未注入则不落审计）。 */
    private ?AuditLog $audit;

    /** 注入的 AuthService（存在时据此实时取身份）。 */
    private ?AuthService $auth = null;

    /** 显式注入的身份（非快照时复用）。 */
    private ?Identity $identity = null;

    /** 是否由调用方显式设定身份（覆盖 auth 实时身份）。 */
    private bool $identityExplicit = false;

    public function __construct(
        AuthService|callable|null $auth = null,
        ?Identity $identity = null,
        ?AuditLog $audit = null,
    ) {
        if ($auth instanceof AuthService) {
            $svc = $auth;
            $this->auth    = $svc;
            $this->checker = static fn(?Identity $id): bool => $svc->canAccessPlatform($id);
        } elseif (is_callable($auth)) {
            $this->checker = $auth;
        } else {
            // 未注入判定器：默认要求已登录身份（无身份拒绝）。
            $this->checker = static fn(?Identity $id): bool => $id !== null;
        }
        if ($identity !== null) {
            $this->identity         = $identity;
            $this->identityExplicit = true;
        }
        $this->audit = $audit;
    }

    /** 显式设定身份（覆盖 AuthService 实时身份；传 null 表示无身份）。 */
    public function setIdentity(?Identity $identity): void
    {
        $this->identity         = $identity;
        $this->identityExplicit = true;
    }

    /** 当前判定的身份：显式注入优先，否则实时取自 AuthService。 */
    public function identity(): ?Identity
    {
        if ($this->identityExplicit) {
            return $this->identity;
        }
        if ($this->auth !== null) {
            return $this->auth->currentIdentity();
        }
        return $this->identity;
    }

    /** 平台面准入判定：无身份或非超管返回 false。 */
    public function isPlatformEntryAllowed(): bool
    {
        $identity = $this->identity();
        if ($identity === null) {
            return false;
        }
        $checker = $this->checker;
        return (bool) $checker($identity);
    }

    /** 强制平台面准入：非法（无身份/非超管）则抛 SecurityException。 */
    public function requirePlatformEntry(): void
    {
        if (!$this->isPlatformEntryAllowed()) {
            throw new SecurityException('平台管理面准入被拒绝：需要超级管理员身份');
        }
        $this->recordEntryAudit();
    }

    /** 调用留痕：在共享 AuditLog 上记一条 type=platform_entry。 */
    private function recordEntryAudit(): void
    {
        if ($this->audit === null) {
            return;
        }
        $identity = $this->identity();
        $id       = $identity !== null ? $identity->id : '';
        $name     = $identity !== null ? $identity->name : '';
        $this->audit->record(
            'info',
            'platform_entry',
            ['type' => 'platform_entry', 'identity' => $id, 'name' => $name],
            'linhub',
            '',
            $id,
        );
    }
}