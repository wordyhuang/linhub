<?php

declare(strict_types=1);

namespace linhub\host\services\inbox;

use linhub\capability\auth\Identity;

/**
 * 站内消息服务契约（IM-1 ~ IM-15）。
 *
 * 功能包面向调用方的统一出口：按用户 / 角色 / 用户组投递，发送时把角色、用户组
 * 解析为具体用户并落收件快照（去重）；收件侧提供未读数、收件箱、已读标记，
 * 供外框架通知中心与管理端投递记录页消费。
 */
interface Inbox
{
    /** 目标类型：用户。 */
    public const TARGET_USER = 'user';
    /** 目标类型：角色。 */
    public const TARGET_ROLE = 'role';
    /** 目标类型：用户组（权限组）。 */
    public const TARGET_GROUP = 'group';
    /** 多类型混合投递（公告页一次选取多类目标）。 */
    public const TARGET_MIXED = 'mixed';

    /**
     * 向指定用户投递（IM-1）。
     *
     * @param array{title:string, content?:string, link?:string} $message 消息内容
     * @param list<string> $userIds 用户标识（本地用户 id 或外部 ref_id 均可）
     * @param array{sender_id?:string, sender_name?:string} $meta 发送方留痕信息
     * @return array{msg_key:string, recipient_count:int}
     */
    public function sendToUsers(string $sourcePackage, array $message, array $userIds, array $meta = []): array;

    /**
     * 向拥有指定角色的全部用户投递（IM-2，发送时解析快照）。
     *
     * @param array{title:string, content?:string, link?:string} $message
     * @param list<string> $roleIds RBAC 角色 id 列表
     * @param array{sender_id?:string, sender_name?:string} $meta
     * @return array{msg_key:string, recipient_count:int}
     */
    public function sendToRoles(string $sourcePackage, array $message, array $roleIds, array $meta = []): array;

    /**
     * 向指定用户组内全部用户投递（IM-3，发送时解析快照）。
     *
     * @param array{title:string, content?:string, link?:string} $message
     * @param list<string> $groupIds RBAC 权限组 id 列表
     * @param array{sender_id?:string, sender_name?:string} $meta
     * @return array{msg_key:string, recipient_count:int}
     */
    public function sendToGroups(string $sourcePackage, array $message, array $groupIds, array $meta = []): array;

    /**
     * 混合目标投递（IM-15 公告页）：一次提交用户/角色/用户组，解析后去重落一条消息。
     *
     * @param array{title:string, content?:string, link?:string} $message
     * @param array{users?:list<string>, roles?:list<string>, groups?:list<string>} $targets
     * @param array{sender_id?:string, sender_name?:string} $meta
     * @return array{msg_key:string, recipient_count:int}
     */
    public function send(string $sourcePackage, array $message, array $targets, array $meta = []): array;

    /** 当前用户未读数（IM-7，红点轻量接口）。 */
    public function unreadCount(Identity $viewer): int;

    /**
     * 当前用户收件箱（IM-8/9/11）。
     *
     * @param string $filter all|unread|read
     * @return array{total:int, page:int, size:int, items:list<array<string,mixed>>}
     */
    public function inbox(Identity $viewer, string $filter = 'all', int $page = 1, int $size = 20): array;

    /** 标记当前用户的某条消息为已读（IM-10/13，幂等）。 */
    public function markRead(Identity $viewer, string $msgKey): void;

    /** 当前用户全部未读置为已读（IM-12），返回受影响条数。 */
    public function markAllRead(Identity $viewer): int;

    /**
     * 投递记录分页（IM-14 管理端，只读排障）。
     *
     * 过滤项：source_pkg / keyword（标题） / target_type / user_id（接收人反查）/
     *         is_read / date_from / date_to。
     * @return array{total:int, page:int, size:int, items:list<array<string,mixed>>}
     */
    public function paginateMessages(array $filter, int $page = 1, int $size = 20): array;

    /** 单条消息主体 + 接收人解析快照（IM-14 下钻）。 */
    public function messageDetail(string $msgKey): ?array;

    /** 平台用量汇总（PS-1 看板）。 */
    public function stats(): array;
}
