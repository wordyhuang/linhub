<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 附件服务 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1 / 8）
 *
 * 数据形态：lg_mgt_attachment 行集（owner_pkg = 包 short）。
 * scope 语义：
 *  - seed：仅导出公开附件（is_public=1）元数据，作为演示/初始化交付基线
 *  - all ：导出该包名下全部附件元数据（含私有）
 *  - range：不支持（附件为状态数据，无流水时间维度）
 *
 * 脱敏（方案 7.1 列白名单）：uploader_id、uploader_name、storage_path 一律不导出。
 * 物理文件本版不随包（方案 R1 待定），导入仅还原元数据行（storage_path 置空）；
 * 保留原 token，使包内容中已引用的附件链接在目标环境仍可定位。
 */
final class AttachmentDataDriver implements ServiceDataDriver
{
    public function __construct(private readonly PlatformStore $store)
    {
    }

    public function capabilities(): array
    {
        return ['scopes' => ['seed', 'all'], 'has_runtime' => false];
    }

    public function export(string $packageKey, string $scope, ?array $range, int $limit): array
    {
        $this->store->ensureAttachmentTables();
        $limit = max(1, $limit);
        $where = 'owner_pkg=' . $this->store->quote($packageKey)
            . ($scope === 'seed' ? ' AND is_public=1' : '');
        $rows = $this->store->db()->query(
            'SELECT token, driver, origin_name, mime, ext, size, is_public, is_image, status, expire_at, created_at, updated_at'
            . ' FROM ' . PlatformStore::ATTACHMENT_TABLE
            . ' WHERE ' . $where
            . ' ORDER BY token LIMIT ' . ($limit + 1)
        );
        $truncated = count($rows) > $limit;
        return [
            'rows'      => $truncated ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => $truncated,
        ];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $this->store->ensureAttachmentTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        // replace：先清空该包名下全部附件元数据，再整批写入
        if ($mode === 'replace') {
            $db->exec('DELETE FROM ' . PlatformStore::ATTACHMENT_TABLE . ' WHERE owner_pkg=' . $ns);
        }
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $token = (string) ($row['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $t = $this->store->quote($token);
            $vals = [
                'driver'      => (string) ($row['driver'] ?? 'local'),
                'origin_name' => (string) ($row['origin_name'] ?? ''),
                'mime'        => (string) ($row['mime'] ?? ''),
                'ext'         => (string) ($row['ext'] ?? ''),
                'size'        => (int) ($row['size'] ?? 0),
                'is_public'   => (int) (bool) ($row['is_public'] ?? 0),
                'is_image'    => (int) (bool) ($row['is_image'] ?? 0),
                'status'      => (string) ($row['status'] ?? 'active'),
                'expire_at'   => (string) ($row['expire_at'] ?? ''),
                'created_at'  => (string) ($row['created_at'] ?? date('c')),
                'updated_at'  => (string) ($row['updated_at'] ?? date('c')),
            ];
            // 归属强制为 packageKey；storage_path/uploader_* 不从包内数据恢复
            $existing = $db->query(
                'SELECT id FROM ' . PlatformStore::ATTACHMENT_TABLE
                . ' WHERE owner_pkg=' . $ns . ' AND token=' . $t . ' LIMIT 1'
            );
            if ($existing !== []) {
                $db->exec(
                    'UPDATE ' . PlatformStore::ATTACHMENT_TABLE . ' SET '
                    . 'driver=' . $this->store->quote($vals['driver']) . ', '
                    . 'origin_name=' . $this->store->quote($vals['origin_name']) . ', '
                    . 'mime=' . $this->store->quote($vals['mime']) . ', '
                    . 'ext=' . $this->store->quote($vals['ext']) . ', '
                    . 'size=' . $vals['size'] . ', '
                    . 'is_public=' . $vals['is_public'] . ', '
                    . 'is_image=' . $vals['is_image'] . ', '
                    . 'status=' . $this->store->quote($vals['status']) . ', '
                    . 'expire_at=' . $this->store->quote($vals['expire_at']) . ', '
                    . 'updated_at=' . $this->store->quote($vals['updated_at'])
                    . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
                );
                $n++;
                continue;
            }
            $db->exec(
                'INSERT INTO ' . PlatformStore::ATTACHMENT_TABLE
                . ' (token, owner_pkg, uploader_id, uploader_name, origin_name, storage_path, driver, mime, ext, size,'
                . ' is_public, is_image, status, expire_at, created_at, updated_at) VALUES ('
                . $t . ', '
                . $ns . ", '', '', "
                . $this->store->quote($vals['origin_name']) . ", '', "
                . $this->store->quote($vals['driver']) . ', '
                . $this->store->quote($vals['mime']) . ', '
                . $this->store->quote($vals['ext']) . ', '
                . $vals['size'] . ', '
                . $vals['is_public'] . ', '
                . $vals['is_image'] . ', '
                . $this->store->quote($vals['status']) . ', '
                . $this->store->quote($vals['expire_at']) . ', '
                . $this->store->quote($vals['created_at']) . ', '
                . $this->store->quote($vals['updated_at']) . ')'
            );
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $this->store->ensureAttachmentTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        $rows = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::ATTACHMENT_TABLE . ' WHERE owner_pkg=' . $ns
        );
        $db->exec('DELETE FROM ' . PlatformStore::ATTACHMENT_TABLE . ' WHERE owner_pkg=' . $ns);
        return (int) ($rows[0]['c'] ?? 0);
    }
}
