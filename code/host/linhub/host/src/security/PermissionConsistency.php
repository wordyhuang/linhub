<?php

declare(strict_types=1);

namespace linhub\host\security;

use linhub\host\rbac\RbacPermissionCalculator;

/**
 * 权限一致性校验（T7.4 / J5 / J6 / T5）
 *
 * 提供"同一用户的菜单可见 / 工作台可见 / 界面替换可用 / 接口访问"四处鉴权，
 * 全部委托同一共享判定 ownsAll（该用户拥有全部所需权限码则允许），
 * 以单一判定逻辑约束四处入口，避免四处逻辑漂移，并返回各入口一致标志。
 */
final class PermissionConsistency
{
    private RbacPermissionCalculator $calculator;

    /** 唯一共享判定：拥有全部所需权限码则允许（可注入以便测试）。 */
    private $ownAll;

    public function __construct(RbacPermissionCalculator $calculator, ?callable $ownAll = null)
    {
        $this->calculator = $calculator;
        $ownAll = $ownAll ?? function (string $userId, array $required): bool {
            $owned = $this->calculator->calcPermissions($userId);
            foreach ($required as $code) {
                if (!in_array($code, $owned, true)) {
                    return false;
                }
            }
            return true;
        };
        $this->ownAll = $ownAll;
    }

    /** 唯一共享判定：拥有全部所需权限码则允许（接口访问语义）。 */
    public function ownsAll(string $userId, array $requiredPermissionCodes): bool
    {
        $fn = $this->ownAll;
        return (bool) $fn($userId, $requiredPermissionCodes);
    }

    /** 菜单可见入口。 */
    public function menuVisible(string $userId, array $requiredPermissionCodes): bool
    {
        return $this->ownsAll($userId, $requiredPermissionCodes);
    }

    /** 工作台可见入口。 */
    public function workspaceVisible(string $userId, array $requiredPermissionCodes): bool
    {
        return $this->ownsAll($userId, $requiredPermissionCodes);
    }

    /** 界面替换可用入口。 */
    public function uiReplaceUsable(string $userId, array $requiredPermissionCodes): bool
    {
        return $this->ownsAll($userId, $requiredPermissionCodes);
    }

    /** 接口访问入口。 */
    public function apiAccess(string $userId, array $requiredPermissionCodes): bool
    {
        return $this->ownsAll($userId, $requiredPermissionCodes);
    }

    /**
     * 断言四处入口鉴权一致，且用户拥有全部所需权限码（一致标志）。
     * 四处共用 ownsAll，结果天然一致；true 当且仅当全部入口允许。
     */
    public function assertConsistent(string $userId, array $requiredPermissionCodes): bool
    {
        $menu = $this->menuVisible($userId, $requiredPermissionCodes);
        $ws   = $this->workspaceVisible($userId, $requiredPermissionCodes);
        $ui   = $this->uiReplaceUsable($userId, $requiredPermissionCodes);
        $api  = $this->apiAccess($userId, $requiredPermissionCodes);
        return $menu && $ws && $ui && $api;
    }

    /**
     * 返回四处入口各自判定结果与整体一致标志（便于审计/测试观察）。
     *
     * @return array<string, bool> menu/workspace/ui_replace/api/consistent
     */
    public function checkEntrances(string $userId, array $requiredPermissionCodes): array
    {
        $menu = $this->menuVisible($userId, $requiredPermissionCodes);
        $ws   = $this->workspaceVisible($userId, $requiredPermissionCodes);
        $ui   = $this->uiReplaceUsable($userId, $requiredPermissionCodes);
        $api  = $this->apiAccess($userId, $requiredPermissionCodes);
        $same = ($menu && $ws && $ui && $api) || (!$menu && !$ws && !$ui && !$api);
        return [
            'menu'       => $menu,
            'workspace'  => $ws,
            'ui_replace' => $ui,
            'api'        => $api,
            'consistent' => $same,
        ];
    }
}