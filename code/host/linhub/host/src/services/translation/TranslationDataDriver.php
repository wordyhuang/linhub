<?php

declare(strict_types=1);

namespace linhub\host\services\translation;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 翻译服务 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1）
 *
 * 数据形态：lg_mgt_translation 行集（namespace = 包 short，唯一键 namespace+dict_key+lang）。
 * scope 语义：
 *  - seed：仅导出公开词条（is_public=1）作为基线
 *  - all ：导出该包名下全部词条
 *  - range：不支持（词条无时间维度）
 * 与 TranslationSeeder 的关系：
 *  - Seeder 负责「包内 translations/ 静态种子文件」的安装导入/升级覆盖/卸载清除（包作者手写）；
 *  - 本驱动负责「运行期词条数据」的随包导出/导入/清除（平台管理端导出产物）。
 *  - 卸载清除（purge）与 Seeder::purgePackage 同语义（DELETE WHERE namespace=short），
 *    admin 卸载端点已收敛至 PlatformDataImporter::purgeOnRemove 统一出口。
 */
final class TranslationDataDriver implements ServiceDataDriver
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
        $this->store->ensureTranslationTables();
        $limit = max(1, $limit);
        $where = 'namespace=' . $this->store->quote($packageKey)
            . ($scope === 'seed' ? ' AND is_public=1' : '');
        $rows = $this->store->db()->query(
            'SELECT dict_key, lang, value, is_public, created_at, updated_at'
            . ' FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE ' . $where
            . ' ORDER BY dict_key, lang LIMIT ' . ($limit + 1)
        );
        $truncated = count($rows) > $limit;
        return [
            'rows'      => $truncated ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => $truncated,
        ];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $this->store->ensureTranslationTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        // replace：先清空该包名下全部词条，再整批写入
        if ($mode === 'replace') {
            $db->exec('DELETE FROM ' . PlatformStore::TRANSLATION_TABLE . ' WHERE namespace=' . $ns);
        }
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = (string) ($row['dict_key'] ?? '');
            $lang = (string) ($row['lang'] ?? '');
            if ($key === '' || $lang === '') {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            $isPublic = (int) (bool) ($row['is_public'] ?? 0);
            $k = $this->store->quote($key);
            $l = $this->store->quote($lang);
            // 归属强制为 packageKey：按唯一键 (namespace, dict_key, lang) upsert，不改写既有 owner_pkg
            $existing = $db->query(
                'SELECT id, owner_pkg FROM ' . PlatformStore::TRANSLATION_TABLE
                . ' WHERE namespace=' . $ns . ' AND dict_key=' . $k . ' AND lang=' . $l . ' LIMIT 1'
            );
            $updatedAt = (string) ($row['updated_at'] ?? date('Y-m-d H:i:s'));
            if ($existing !== []) {
                $db->exec(
                    'UPDATE ' . PlatformStore::TRANSLATION_TABLE . ' SET '
                    . 'value=' . $this->store->quote($value) . ', '
                    . 'is_public=' . $isPublic . ', '
                    . 'updated_at=' . $this->store->quote($updatedAt)
                    . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
                );
                $n++;
                continue;
            }
            $db->exec(
                'INSERT INTO ' . PlatformStore::TRANSLATION_TABLE
                . ' (namespace, dict_key, lang, value, is_public, owner_pkg, created_at, updated_at) VALUES ('
                . $ns . ', '
                . $k . ', '
                . $l . ', '
                . $this->store->quote($value) . ', '
                . $isPublic . ', '
                . $ns . ', '
                . $this->store->quote((string) ($row['created_at'] ?? date('Y-m-d H:i:s'))) . ', '
                . $this->store->quote($updatedAt) . ')'
            );
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $this->store->ensureTranslationTables();
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($packageKey)
        );
        $db->exec('DELETE FROM ' . PlatformStore::TRANSLATION_TABLE . ' WHERE namespace=' . $this->store->quote($packageKey));
        return (int) ($rows[0]['c'] ?? 0);
    }
}
