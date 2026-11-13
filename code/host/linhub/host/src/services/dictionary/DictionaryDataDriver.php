<?php

declare(strict_types=1);

namespace linhub\host\services\dictionary;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 数据字典 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1）
 *
 * 数据形态：lg_mgt_dict_item 行集（namespace = 包 short，owner_pkg 隔离）。
 * scope 语义：
 *  - seed：仅导出公开字典项（is_public=1）作为基线，不含包私有配置
 *  - all ：导出该包名下全部字典项（含私有）
 *  - range：不支持（字典为 KV 持久化，无时间维度）
 * 导出行 value 为 JSON 字符串，原样导出导入（无损，不做编解码）。
 */
final class DictionaryDataDriver implements ServiceDataDriver
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
        $this->store->ensureDictTables();
        $limit = max(1, $limit);
        $where = 'owner_pkg=' . $this->store->quote($packageKey)
            . ($scope === 'seed' ? ' AND is_public=1' : '');
        $rows = $this->store->db()->query(
            'SELECT namespace, dict_key, value, value_type, is_public, remark, created_at, updated_at'
            . ' FROM ' . PlatformStore::DICT_TABLE
            . ' WHERE ' . $where
            . ' ORDER BY dict_key LIMIT ' . ($limit + 1)
        );
        $truncated = count($rows) > $limit;
        return [
            'rows'      => $truncated ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => $truncated,
        ];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $this->store->ensureDictTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        // replace：先清空该包名下全部字典项，再整批写入
        if ($mode === 'replace') {
            $db->exec('DELETE FROM ' . PlatformStore::DICT_TABLE . ' WHERE owner_pkg=' . $ns);
        }
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = (string) ($row['dict_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            $type = (string) ($row['value_type'] ?? 'string');
            $isPublic = (int) (bool) ($row['is_public'] ?? 0);
            $remark = (string) ($row['remark'] ?? '');
            $k = $this->store->quote($key);
            // 归属强制为 packageKey：更新仅覆盖数据列，不得改写既有 owner_pkg
            $existing = $db->query(
                'SELECT id FROM ' . PlatformStore::DICT_TABLE
                . ' WHERE namespace=' . $ns . ' AND dict_key=' . $k . ' LIMIT 1'
            );
            if ($existing !== []) {
                $db->exec(
                    'UPDATE ' . PlatformStore::DICT_TABLE . ' SET '
                    . 'value=' . $this->store->quote($value) . ', '
                    . 'value_type=' . $this->store->quote($type) . ', '
                    . 'is_public=' . $isPublic . ', '
                    . 'remark=' . $this->store->quote($remark) . ', '
                    . 'updated_at=' . $this->store->quote((string) ($row['updated_at'] ?? date('Y-m-d H:i:s')))
                    . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
                );
                $n++;
                continue;
            }
            $db->exec(
                'INSERT INTO ' . PlatformStore::DICT_TABLE
                . ' (namespace, dict_key, value, value_type, owner_pkg, is_public, remark, created_at, updated_at) VALUES ('
                . $ns . ', '
                . $k . ', '
                . $this->store->quote($value) . ', '
                . $this->store->quote($type) . ', '
                . $ns . ', '
                . $isPublic . ', '
                . $this->store->quote($remark) . ', '
                . $this->store->quote((string) ($row['created_at'] ?? date('Y-m-d H:i:s'))) . ', '
                . $this->store->quote((string) ($row['updated_at'] ?? date('Y-m-d H:i:s'))) . ')'
            );
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $this->store->ensureDictTables();
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::DICT_TABLE
            . ' WHERE owner_pkg=' . $this->store->quote($packageKey)
        );
        $db->exec('DELETE FROM ' . PlatformStore::DICT_TABLE . ' WHERE owner_pkg=' . $this->store->quote($packageKey));
        return (int) ($rows[0]['c'] ?? 0);
    }
}
