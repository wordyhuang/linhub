<?php

declare(strict_types=1);

/*
 * 文档创建时间：2026-10-20
 * 最后修改时间：2026-11-10
 * 当前版本：V1.2
 * 适用对象：功能包开发者
 * 核心目标：向功能包开放「用户 / 角色 / 权限 / 权限组」只读查询能力
 * 文档简略描述：聚合宿主 RBAC 数据源（RbacUser / RbacRoles / RbacPermissionGroup / PermissionRegistry / RbacPermissionCalculator）。
 *               V1.2 收缩：角色仅限 5 个系统预设角色且不可增删改，移除 ensureRole 写方法；全部方法只读、不抛异常。
 */

namespace linhub\host\services\auth;

/**
 * 平台账号与权限服务契约（AQ-1 ~ AQ-11）。
 *
 * 设计原则：
 *  - 全只读：所有方法不得修改宿主 / UAIS 数据；任何底层异常都被吞掉返回空结构，功能包侧永远不抛。
 *  - 角色收敛（V1.2）：系统仅提供 5 个预设角色（工作者 / 审核员 / 功能管理员 / 系统管理员 / 超级管理员），
 *    角色不可新增、改名或删除；权限项 roles 只能落这 5 个预设角色英文键或为空（不限角色）。
 *  - 收敛字段：只暴露功能包「鉴权 / 菜单 / 归属判断」所需的必要字段，不返回 token、密码、会话内部态。
 *  - 身份入参：除 current* 系列外，入参均为字符串 id（本地用户 id、角色 id、权限组 id、权限点 code）；UAIS refId 单独提供按 refId 查询入口。
 *
 * 对应宿主后台：admin/src/controller/{User,Role,Group,Permission,Auth}.php；
 * 对应底层数据源：HostBootstrap::rbacUsers()/roles()/permissionGroups()/permissions()/permissionCalculator()。
 */
interface RbacQuery
{
    /**
     * 当前登录态快照（聚合 loginstate 已有能力，避免功能包再注入一个服务）。
     *
     * @return array{
     *   is_login:bool, guest:bool, user_id:string, username:string, display_name:string,
     *   is_super_admin:bool, tags:list<string>, roles:list<string>
     * }
     */
    public function currentSnapshot(): array;

    /** 当前用户本地 id（游客返回空串）。 */
    public function currentUserId(): string;

    /** 当前用户最终权限点 code 列表（未登录返回空）。 */
    public function currentPermCodes(): array;

    /** 当前用户所属角色 id 列表（未登录返回空）。 */
    public function currentRoleIds(): array;

    /** 当前用户所属权限组 id 列表（未登录返回空）。 */
    public function currentGroupIds(): array;

    /**
     * 按本地用户 id 查询档案。
     *
     * @return array{id:string,name:string,refId:string,lastLoginAt:string,roles:list<string>,groups:list<string>,permCodes:list<string>}
     *         不存在返回 ['id'=>'', ...空值]。
     */
    public function userById(string $userId): array;

    /** 按 UAIS refId 查询本地建档档案（结构同 userById；未建档返回空）。 */
    public function userByRefId(string $refId): array;

    /**
     * 用户列表（可选关键字，按姓名 / refId 不区分大小写）。
     *
     * @return list<array{id:string,name:string,refId:string,lastLoginAt:string}>
     */
    public function listUsers(string $keyword = ''): array;

    /**
     * 用户最终权限点 code 列表（直接按用户 id 计算，避免功能包再自己拿 calculator）。
     *
     * @return list<string>
     */
    public function permCodesOf(string $userId): array;

    /**
     * 角色列表（仅 5 个系统预设角色）。
     *
     * @return list<array{id:string,name:string,readonly:bool,userCount:int}>
     */
    public function listRoles(): array;

    /** 按角色 id 查询档案（不存在返回 id 空）。 */
    public function roleById(string $roleId): array;

    /**
     * 指定角色下的用户 id 列表。
     *
     * @return list<string>
     */
    public function userIdsOfRole(string $roleId): array;

    /**
     * 权限组列表。
     *
     * @return list<array{id:string,name:string,permCodes:list<string>}>
     */
    public function listGroups(): array;

    /** 按权限组 id 查询档案（不存在返回 id 空）。 */
    public function groupById(string $groupId): array;

    /**
     * 权限点清单（功能包用它做「菜单 / 按钮可见性」判断）。
     *
     * @return list<array{code:string,title:string,description:string,group:string,short:string,open:bool,roles:list<string>}>
     */
    public function listPermissions(): array;

    /** 权限点是否存在。 */
    public function hasPermission(string $code): bool;

    /** 用户是否拥有指定权限点（未登录 / 不存在 / 无此权限均返回 false）。 */
    public function userHasPermission(string $userId, string $code): bool;
}
