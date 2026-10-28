<?php

declare(strict_types=1);

namespace linhub\host\services\loginstate;

/**
 * 登录状态服务契约（LS-1 ~ LS-6）。
 *
 * 只读统一出口，屏蔽底层 session / UAIS 解析细节；未登录返回游客态，不抛异常。
 * 字段收敛为必要项：登录状态、用户 ID、用户名、显示名、tags 与角色类型。
 */
interface LoginState
{
    /**
     * 当前登录态快照（LS-1/2/3/4）。
     *
     * @return array{
     *   is_login:bool, guest:bool, user_id:string, username:string, display_name:string,
     *   is_super_admin:bool, tags:list<string>, roles:list<string>
     * }
     */
    public function snapshot(): array;

    /** 当前是否已登录（LS-1）。 */
    public function isLogin(): bool;

    /** 当前用户 ID（游客返回空串，LS-2）。 */
    public function userId(): string;

    /** 当前身份标签 tags（LS-3）。 */
    public function tags(): array;
}
