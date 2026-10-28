<?php

declare(strict_types=1);

namespace linhub\host\services\inbox;

use linhub\capability\auth\Identity;
use linhub\host\rbac\DbRbacStorage;
use linhub\host\rbac\RbacStorage;
use linhub\host\services\support\PlatformStore;
use think\facade\Env;

/**
 * 站内消息服务实现（IM-1 ~ IM-15）。
 *
 * 两张表（linhub\host\services\support\PlatformStore）：
 *  - lg_mgt_message：消息主体（标题/正文/链接/来源/发送方/目标类型/接收人数）；
 *  - lg_mgt_message_recipient：收件快照（msg_key + user_id + 已读状态）。
 *
 * 角色 / 用户组在发送时一次性解析为具体用户（O16 快照），同一投递去重；
 * 接收人以本地 RBAC 用户 id 落库，外部 ref_id 先反查本地用户。
 */
final class InboxService implements Inbox
{
    private PlatformStore $store;
    private ?RbacStorage $rbac;

    /** 注入为 null 时惰性自建，满足 ServiceInjector 的无参友好构造。 */
    public function __construct(?PlatformStore $store = null, ?RbacStorage $rbac = null)
    {
        $this->store = $store ?? new PlatformStore();
        $this->rbac = $rbac;
    }

    public function sendToUsers(string $sourcePackage, array $message, array $userIds, array $meta = []): array
    {
        return $this->dispatch($sourcePackage, $message, [self::TARGET_USER => $userIds], self::TARGET_USER, $meta);
    }

    public function sendToRoles(string $sourcePackage, array $message, array $roleIds, array $meta = []): array
    {
        return $this->dispatch($sourcePackage, $message, [self::TARGET_ROLE => $roleIds], self::TARGET_ROLE, $meta);
    }

    public function sendToGroups(string $sourcePackage, array $message, array $groupIds, array $meta = []): array
    {
        return $this->dispatch($sourcePackage, $message, [self::TARGET_GROUP => $groupIds], self::TARGET_GROUP, $meta);
    }

    public function send(string $sourcePackage, array $message, array $targets, array $meta = []): array
    {
        $buckets = [
            self::TARGET_USER  => array_values((array) ($targets['users'] ?? [])),
            self::TARGET_ROLE  => array_values((array) ($targets['roles'] ?? [])),
            self::TARGET_GROUP => array_values((array) ($targets['groups'] ?? [])),
        ];
        $nonEmpty = count(array_filter($buckets, static fn (array $ids) => $ids !== []));
        $targetType = $nonEmpty > 1 ? self::TARGET_MIXED : (string) array_key_first(array_filter($buckets, static fn (array $ids) => $ids !== []));

        return $this->dispatch($sourcePackage, $message, $buckets, $targetType !== '' ? $targetType : self::TARGET_USER, $meta);
    }

    public function unreadCount(Identity $viewer): int
    {
        $this->store->ensureMessageTables();
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECIPIENT_TABLE
            . ' WHERE user_id IN (' . $this->viewerIdLiterals($viewer) . ') AND is_read = 0'
        );
        return isset($rows[0]) ? (int) $rows[0]['c'] : 0;
    }

    public function inbox(Identity $viewer, string $filter = 'all', int $page = 1, int $size = 20): array
    {
        $this->store->ensureMessageTables();
        $page = max(1, $page);
        $size = max(1, min(100, $size));

        $where = 'r.user_id IN (' . $this->viewerIdLiterals($viewer) . ')';
        if ($filter === 'unread') {
            $where .= ' AND r.is_read = 0';
        } elseif ($filter === 'read') {
            $where .= ' AND r.is_read = 1';
        }

        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECIPIENT_TABLE . ' r WHERE ' . $where
        );
        $total = isset($totalRows[0]) ? (int) $totalRows[0]['c'] : 0;

        $items = $this->store->db()->query(
            'SELECT m.msg_key, m.title, m.content, m.link, m.source_pkg, m.sender_id, m.sender_name,'
            . ' m.target_type, m.created_at, r.is_read, r.read_at'
            . ' FROM ' . PlatformStore::RECIPIENT_TABLE . ' r'
            . ' INNER JOIN ' . PlatformStore::MESSAGE_TABLE . ' m ON m.msg_key = r.msg_key'
            . ' WHERE ' . $where
            . ' ORDER BY m.id DESC'
            . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map([$this, 'decorateInboxRow'], $items),
        ];
    }

    public function markRead(Identity $viewer, string $msgKey): void
    {
        if ($msgKey === '') {
            return;
        }
        $this->store->ensureMessageTables();
        $this->store->db()->exec(
            'UPDATE ' . PlatformStore::RECIPIENT_TABLE
            . ' SET is_read = 1, read_at = ' . $this->store->quote(date('c'))
            . ' WHERE msg_key = ' . $this->store->quote($msgKey)
            . ' AND is_read = 0'
            . ' AND user_id IN (' . $this->viewerIdLiterals($viewer) . ')'
        );
    }

    public function markAllRead(Identity $viewer): int
    {
        $this->store->ensureMessageTables();
        $affected = $this->unreadCount($viewer);
        if ($affected > 0) {
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::RECIPIENT_TABLE
                . ' SET is_read = 1, read_at = ' . $this->store->quote(date('c'))
                . ' WHERE is_read = 0'
                . ' AND user_id IN (' . $this->viewerIdLiterals($viewer) . ')'
            );
        }
        return $affected;
    }

    public function paginateMessages(array $filter, int $page = 1, int $size = 20): array
    {
        $this->store->ensureMessageTables();
        $page = max(1, $page);
        $size = max(1, min(200, $size));

        $where = '1=1';
        if (($filter['source_pkg'] ?? '') !== '') {
            $where .= ' AND m.source_pkg = ' . $this->store->quote((string) $filter['source_pkg']);
        }
        if (($filter['target_type'] ?? '') !== '') {
            $where .= ' AND m.target_type = ' . $this->store->quote((string) $filter['target_type']);
        }
        if (($filter['keyword'] ?? '') !== '') {
            $where .= ' AND m.title LIKE ' . $this->store->quote('%' . $this->escapeLike((string) $filter['keyword']) . '%');
        }
        if (($filter['date_from'] ?? '') !== '') {
            $where .= ' AND m.created_at >= ' . $this->store->quote((string) $filter['date_from']);
        }
        if (($filter['date_to'] ?? '') !== '') {
            $where .= ' AND m.created_at <= ' . $this->store->quote((string) $filter['date_to']);
        }
        // 接收人反查 / 已读状态过滤需 EXISTS 收件表
        if (($filter['user_id'] ?? '') !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . PlatformStore::RECIPIENT_TABLE . ' r2'
                . ' WHERE r2.msg_key = m.msg_key AND r2.user_id = ' . $this->store->quote((string) $filter['user_id']) . ')';
        }
        if (array_key_exists('is_read', $filter) && $filter['is_read'] !== '' && $filter['is_read'] !== null) {
            $read = (int) $filter['is_read'];
            if (($filter['user_id'] ?? '') !== '') {
                $where = str_replace(
                    'r2.user_id = ' . $this->store->quote((string) $filter['user_id']) . ')',
                    'r2.user_id = ' . $this->store->quote((string) $filter['user_id']) . ' AND r2.is_read = ' . $read . ')',
                    $where
                );
            }
        }

        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::MESSAGE_TABLE . ' m WHERE ' . $where
        );
        $total = isset($totalRows[0]) ? (int) $totalRows[0]['c'] : 0;

        $items = $this->store->db()->query(
            'SELECT m.id, m.msg_key, m.title, m.content, m.link, m.source_pkg, m.sender_id, m.sender_name,'
            . ' m.target_type, m.target_ref, m.recipient_count, m.created_at,'
            . ' (SELECT COUNT(*) FROM ' . PlatformStore::RECIPIENT_TABLE . ' r WHERE r.msg_key = m.msg_key AND r.is_read = 1) AS read_count'
            . ' FROM ' . PlatformStore::MESSAGE_TABLE . ' m'
            . ' WHERE ' . $where
            . ' ORDER BY m.id DESC'
            . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map(static function (array $row): array {
                $row['recipient_count'] = (int) $row['recipient_count'];
                $row['read_count'] = (int) ($row['read_count'] ?? 0);
                return $row;
            }, $items),
        ];
    }

    public function messageDetail(string $msgKey): ?array
    {
        if ($msgKey === '') {
            return null;
        }
        $this->store->ensureMessageTables();
        $rows = $this->store->db()->query(
            'SELECT id, msg_key, title, content, link, source_pkg, sender_id, sender_name,'
            . ' target_type, target_ref, recipient_count, created_at'
            . ' FROM ' . PlatformStore::MESSAGE_TABLE
            . ' WHERE msg_key = ' . $this->store->quote($msgKey)
            . ' ORDER BY id DESC LIMIT 1'
        );
        if (!isset($rows[0])) {
            return null;
        }
        $message = $rows[0];
        $message['recipient_count'] = (int) $message['recipient_count'];

        $recipients = $this->store->db()->query(
            'SELECT user_id, user_name, resolved_from, is_read, read_at, created_at'
            . ' FROM ' . PlatformStore::RECIPIENT_TABLE
            . ' WHERE msg_key = ' . $this->store->quote($msgKey)
            . ' ORDER BY id ASC'
        );
        $message['recipients'] = array_map(static function (array $row): array {
            $row['is_read'] = (int) $row['is_read'];
            return $row;
        }, $recipients);

        return $message;
    }

    public function stats(): array
    {
        $this->store->ensureMessageTables();
        $totalRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::MESSAGE_TABLE
        );
        $unreadRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECIPIENT_TABLE . ' WHERE is_read = 0'
        );
        $recipientRows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECIPIENT_TABLE
        );

        return [
            'message_total'   => isset($totalRows[0]) ? (int) $totalRows[0]['c'] : 0,
            'recipient_total' => isset($recipientRows[0]) ? (int) $recipientRows[0]['c'] : 0,
            'unread_total'    => isset($unreadRows[0]) ? (int) $unreadRows[0]['c'] : 0,
        ];
    }

    /**
     * 统一投递：解析目标 → 去重 → 落消息主体 + 收件快照 → 未读超限裁剪（O17）。
     *
     * @param array{title:string, content?:string, link?:string} $message
     * @param array<string, list<string>> $buckets user/role/group 三桶目标 id
     * @param array{sender_id?:string, sender_name?:string} $meta
     * @return array{msg_key:string, recipient_count:int}
     */
    private function dispatch(string $sourcePackage, array $message, array $buckets, string $targetType, array $meta): array
    {
        $title = trim((string) ($message['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('站内消息标题不能为空');
        }

        $this->store->ensureMessageTables();

        // 解析为 [userKey => [id,name,from]]，同次投递去重（IM-5）
        $resolved = [];
        foreach ($buckets[self::TARGET_USER] ?? [] as $rawId) {
            $user = $this->resolveUser((string) $rawId);
            if ($user !== null) {
                $resolved[$user['id']] = ['id' => $user['id'], 'name' => $user['name'], 'from' => self::TARGET_USER];
            }
        }
        foreach ($this->usersWithRbac($buckets[self::TARGET_ROLE] ?? [], 'rolesOf') as $uid => $name) {
            $resolved[$uid] ??= ['id' => $uid, 'name' => $name, 'from' => self::TARGET_ROLE];
        }
        foreach ($this->usersWithRbac($buckets[self::TARGET_GROUP] ?? [], 'groupsOf') as $uid => $name) {
            $resolved[$uid] ??= ['id' => $uid, 'name' => $name, 'from' => self::TARGET_GROUP];
        }

        // 目标解析为空（用户不存在 / 角色组无成员）时不落空消息，避免投递记录噪音
        if ($resolved === []) {
            return ['msg_key' => '', 'recipient_count' => 0];
        }

        $msgKey = $this->newMsgKey();
        $now = date('c');
        $targetRef = $this->buildTargetRef($buckets);

        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::MESSAGE_TABLE
            . ' (msg_key, title, content, link, source_pkg, sender_id, sender_name, target_type, target_ref,'
            . ' recipient_count, created_at) VALUES ('
            . $this->store->quote($msgKey) . ', '
            . $this->store->quote($title) . ', '
            . $this->store->quote(trim((string) ($message['content'] ?? ''))) . ', '
            . $this->store->quote(trim((string) ($message['link'] ?? ''))) . ', '
            . $this->store->quote(trim($sourcePackage)) . ', '
            . $this->store->quote((string) ($meta['sender_id'] ?? '')) . ', '
            . $this->store->quote((string) ($meta['sender_name'] ?? '')) . ', '
            . $this->store->quote($targetType) . ', '
            . $this->store->quote($targetRef) . ', '
            . count($resolved) . ', '
            . $this->store->quote($now) . ')'
        );

        foreach (array_values($resolved) as $recipient) {
            $this->store->db()->exec(
                'INSERT INTO ' . PlatformStore::RECIPIENT_TABLE
                . ' (msg_key, user_id, user_name, resolved_from, is_read, read_at, created_at) VALUES ('
                . $this->store->quote($msgKey) . ', '
                . $this->store->quote((string) $recipient['id']) . ', '
                . $this->store->quote((string) $recipient['name']) . ', '
                . $this->store->quote((string) $recipient['from']) . ', '
                . '0, ' . $this->store->quote('') . ', ' . $this->store->quote($now) . ')'
            );
            $this->trimUnread((string) $recipient['id']);
        }

        return ['msg_key' => $msgKey, 'recipient_count' => count($resolved)];
    }

    /** 外部 ref_id / 本地 id 统一反查为本地用户；无本地用户时直接以原值落快照。 */
    private function resolveUser(string $rawId): ?array
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return null;
        }
        $users = $this->rbac()?->section(RbacStorage::USERS) ?? [];
        if (isset($users[$rawId])) {
            return ['id' => (string) $users[$rawId]['id'], 'name' => (string) ($users[$rawId]['name'] ?? '')];
        }
        foreach ($users as $user) {
            if ((string) ($user['ref_id'] ?? '') === $rawId) {
                return ['id' => (string) $user['id'], 'name' => (string) ($user['name'] ?? '')];
            }
        }
        // 无本地用户档案（如纯 UAIS 用户）：以外部标识直接收件，通知中心用 viewer 标识匹配
        return ['id' => $rawId, 'name' => ''];
    }

    /**
     * 按角色 / 用户组反查成员。
     *
     * @param list<string> $targetIds
     * @return array<string, string> userId => name
     */
    private function usersWithRbac(array $targetIds, string $relation): array
    {
        $targetIds = array_values(array_unique(array_filter(array_map('strval', $targetIds), static fn (string $v) => trim($v) !== '')));
        if ($targetIds === []) {
            return [];
        }
        $rbac = $this->rbac();
        if ($rbac === null) {
            return [];
        }
        $map = $relation === 'rolesOf'
            ? $rbac->section(RbacStorage::USER_ROLES)
            : $rbac->section(RbacStorage::USER_GROUPS);
        $want = array_flip($targetIds);
        $out = [];
        foreach ($map as $userId => $ownedIds) {
            if (array_intersect_key($want, array_flip(array_map('strval', (array) $ownedIds))) !== []) {
                $user = $rbac->find(RbacStorage::USERS, (string) $userId);
                $out[(string) $userId] = $user === null ? '' : (string) ($user['name'] ?? '');
            }
        }
        return $out;
    }

    /** 未读条数上限裁剪（MSG_UNREAD_LIMIT，0=不限）：超出时把该用户最旧未读置为已读。 */
    private function trimUnread(string $userId): void
    {
        $limit = (int) Env::get('MSG_UNREAD_LIMIT', '0');
        if ($limit <= 0) {
            return;
        }
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::RECIPIENT_TABLE
            . ' WHERE user_id = ' . $this->store->quote($userId) . ' AND is_read = 0'
        );
        $count = isset($rows[0]) ? (int) $rows[0]['c'] : 0;
        $overflow = $count - $limit;
        if ($overflow <= 0) {
            return;
        }
        $stale = $this->store->db()->query(
            'SELECT msg_key FROM ' . PlatformStore::RECIPIENT_TABLE
            . ' WHERE user_id = ' . $this->store->quote($userId) . ' AND is_read = 0'
            . ' ORDER BY id ASC LIMIT ' . $overflow
        );
        foreach ($stale as $row) {
            $this->store->db()->exec(
                'UPDATE ' . PlatformStore::RECIPIENT_TABLE
                . ' SET is_read = 1, read_at = ' . $this->store->quote(date('c'))
                . ' WHERE user_id = ' . $this->store->quote($userId)
                . ' AND msg_key = ' . $this->store->quote((string) $row['msg_key'])
            );
        }
    }

    /**
     * 收件身份匹配字面量集合：本地 id 与外部 ref_id 均可能用于落库，
     * 故用 viewer.id 反查本地用户后同时匹配，保证两种口径都能命中通知。
     */
    private function viewerIdLiterals(Identity $viewer): string
    {
        $ids = [$viewer->id];
        $rbac = $this->rbac();
        if ($rbac !== null) {
            $users = $rbac->section(RbacStorage::USERS);
            if (isset($users[$viewer->id]) && (string) ($users[$viewer->id]['ref_id'] ?? '') !== '') {
                $ids[] = (string) $users[$viewer->id]['ref_id'];
            } else {
                foreach ($users as $user) {
                    if ((string) ($user['ref_id'] ?? '') === $viewer->id && isset($user['id'])) {
                        $ids[] = (string) $user['id'];
                        break;
                    }
                }
            }
        }
        return implode(',', array_map(fn (string $id) => $this->store->quote($id), array_values(array_unique($ids))));
    }

    private function decorateInboxRow(array $row): array
    {
        $row['is_read'] = (int) ($row['is_read'] ?? 0);
        return $row;
    }

    /** @param array<string, list<string>> $buckets */
    private function buildTargetRef(array $buckets): string
    {
        $parts = [];
        foreach ([self::TARGET_USER, self::TARGET_ROLE, self::TARGET_GROUP] as $type) {
            $ids = array_values(array_filter(array_map('strval', $buckets[$type] ?? []), static fn (string $v) => trim($v) !== ''));
            if ($ids !== []) {
                $parts[] = $type . ':' . implode(',', array_slice($ids, 0, 50));
            }
        }
        return implode(';', $parts);
    }

    private function newMsgKey(): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), 0, 24);
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    private function rbac(): ?RbacStorage
    {
        if ($this->rbac !== null) {
            return $this->rbac;
        }
        // 统一口径：RBAC 已迁移到数据库（DbRbacStorage），不再读文件 data/rbac/rbac.json
        return $this->rbac = new DbRbacStorage();
    }
}
