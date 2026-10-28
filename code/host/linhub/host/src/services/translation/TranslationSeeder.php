<?php

declare(strict_types=1);

namespace linhub\host\services\translation;

use linhub\host\migration\DbGateway;
use linhub\host\services\support\PlatformStore;

/**
 * 翻译种子导入器（包生命周期接线）。
 *
 * 包根固定目录 translations/，每语言一文件（translations/zh-CN.php 等，文件名即 BCP-47 语言码），
 * 文件返回 [key => 译文]，无需在 manifest 声明。三种语义：
 *  - 安装：幂等 upsert（同 (namespace, key, lang) 覆盖 value）；
 *  - 升级：事务内 DELETE 该包 namespace 全部词条 → 重导新种子（D3 全量覆盖）；
 *  - 卸载：DELETE 该包 namespace 全部词条。
 *
 * P6 容错：目录缺失、文件不可 include、返回非数组、值非 string —— 一律跳过，不中断安装/升级。
 */
final class TranslationSeeder
{
    /** 包根下的种子目录名（固定约定）。 */
    public const SEED_DIR = 'translations';

    private PlatformStore $store;

    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    /**
     * 安装期导入：逐语言幂等 upsert，保留后台已有人工修改之外的同 key 覆盖。
     *
     * @return int 写入/更新的词条数
     */
    public function importPackage(string $short, string $packageDir): int
    {
        $short = $this->assertShort($short);
        $seeds = $this->readSeeds($packageDir);
        if ($seeds === []) {
            return 0;
        }

        $this->store->ensureTranslationTables();
        $existing = $this->existingIds($short);
        $now = date('c');
        $count = 0;

        foreach ($seeds as $lang => $entries) {
            foreach ($entries as $key => $value) {
                $id = $existing[$lang . "\x1f" . $key] ?? null;
                if ($id === null) {
                    $this->store->db()->exec(
                        'INSERT INTO ' . PlatformStore::TRANSLATION_TABLE
                        . ' (namespace, dict_key, lang, value, is_public, owner_pkg, created_at, updated_at) VALUES ('
                        . $this->store->quote($short) . ', '
                        . $this->store->quote($key) . ', '
                        . $this->store->quote($lang) . ', '
                        . $this->store->quote($value) . ', '
                        . '0, '
                        . $this->store->quote($short) . ', '
                        . $this->store->quote($now) . ', '
                        . $this->store->quote($now) . ')'
                    );
                } else {
                    $this->store->db()->exec(
                        'UPDATE ' . PlatformStore::TRANSLATION_TABLE
                        . ' SET value=' . $this->store->quote($value)
                        . ', owner_pkg=' . $this->store->quote($short)
                        . ', updated_at=' . $this->store->quote($now)
                        . ' WHERE id=' . (int) $id
                    );
                }
                $count++;
            }
        }

        TranslatorService::flushCache();
        return $count;
    }

    /**
     * 升级期全量覆盖：事务内先删除该包命名空间全部词条，再重导新种子（D3）。
     *
     * @return int 重导写入的词条数
     */
    public function overwritePackage(string $short, string $packageDir): int
    {
        $short = $this->assertShort($short);
        $seeds = $this->readSeeds($packageDir);

        $this->store->ensureTranslationTables();
        $db = $this->store->db();
        $db->begin();
        try {
            $db->exec(
                'DELETE FROM ' . PlatformStore::TRANSLATION_TABLE
                . ' WHERE namespace=' . $this->store->quote($short)
            );
            $count = 0;
            $now = date('c');
            foreach ($seeds as $lang => $entries) {
                foreach ($entries as $key => $value) {
                    $db->exec(
                        'INSERT INTO ' . PlatformStore::TRANSLATION_TABLE
                        . ' (namespace, dict_key, lang, value, is_public, owner_pkg, created_at, updated_at) VALUES ('
                        . $this->store->quote($short) . ', '
                        . $this->store->quote($key) . ', '
                        . $this->store->quote($lang) . ', '
                        . $this->store->quote($value) . ', '
                        . '0, '
                        . $this->store->quote($short) . ', '
                        . $this->store->quote($now) . ', '
                        . $this->store->quote($now) . ')'
                    );
                    $count++;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw new \RuntimeException('翻译种子覆盖失败：' . $e->getMessage(), 0, $e);
        }

        TranslatorService::flushCache();
        return $count;
    }

    /**
     * 卸载期清除：删除该包命名空间全部词条。
     *
     * @return int 删除的行数
     */
    public function purgePackage(string $short): int
    {
        $short = $this->assertShort($short);
        $this->store->ensureTranslationTables();
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($short)
        );
        $this->store->db()->exec(
            'DELETE FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($short)
        );
        TranslatorService::flushCache();
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * 读取包内种子文件（P6 容错，任何异常情形按空种子处理）。
     *
     * @return array<string, array<string, string>> lang => [key => value]
     */
    public function readSeeds(string $packageDir): array
    {
        $dir = rtrim($packageDir, '/\\') . '/' . self::SEED_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php') ?: [];
        $out = [];
        foreach ($files as $file) {
            $lang = basename($file, '.php');
            if (!$this->isValidLang($lang)) {
                continue;
            }
            try {
                $data = include $file;
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $entries = [];
            foreach ($data as $key => $value) {
                $key = (string) $key;
                if (!$this->isValidKey($key) || !is_string($value)) {
                    continue;
                }
                $entries[$key] = $value;
            }
            if ($entries !== []) {
                $out[$lang] = $entries;
            }
        }
        return $out;
    }

    // —— 生命周期槽位工厂 ——

    /** 安装槽位：function(array $manifest): void；无 DB 网关时静默跳过。 */
    public static function installHandler(string $loadRoot, ?DbGateway $db = null): callable
    {
        $loadRoot = rtrim($loadRoot, '/\\');
        return static function (array $manifest) use ($loadRoot, $db): void {
            if ($db === null) {
                return;
            }
            $short = (string) ($manifest['short'] ?? '');
            if ($short === '') {
                return;
            }
            (new self(new PlatformStore($db)))->importPackage($short, $loadRoot . '/' . $short);
        };
    }

    /** 升级槽位：function(array $manifest): void；全量覆盖该包 namespace。 */
    public static function upgradeHandler(string $loadRoot, ?DbGateway $db = null): callable
    {
        $loadRoot = rtrim($loadRoot, '/\\');
        return static function (array $manifest) use ($loadRoot, $db): void {
            if ($db === null) {
                return;
            }
            $short = (string) ($manifest['short'] ?? '');
            if ($short === '') {
                return;
            }
            (new self(new PlatformStore($db)))->overwritePackage($short, $loadRoot . '/' . $short);
        };
    }

    /** 卸载槽位：function(string $short): void；清除该包 namespace。 */
    public static function removeHandler(?DbGateway $db = null): callable
    {
        return static function (string $short) use ($db): void {
            if ($db === null || $short === '') {
                return;
            }
            (new self(new PlatformStore($db)))->purgePackage($short);
        };
    }

    // —— 内部实现 ——

    /** @return array<string, int> "lang\x1fkey" => id */
    private function existingIds(string $short): array
    {
        $rows = $this->store->db()->query(
            'SELECT id, dict_key, lang FROM ' . PlatformStore::TRANSLATION_TABLE
            . ' WHERE namespace=' . $this->store->quote($short)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['lang'] . "\x1f" . (string) $r['dict_key']] = (int) $r['id'];
        }
        return $out;
    }

    private function assertShort(string $short): string
    {
        $v = trim($short);
        if ($v === '' || strlen($v) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $v)) {
            throw new \InvalidArgumentException('功能包短名不合法，无法导入翻译种子：' . $short);
        }
        return $v;
    }

    private function isValidLang(string $lang): bool
    {
        return $lang !== ''
            && strlen($lang) <= 16
            && (bool) preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $lang);
    }

    private function isValidKey(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= 191
            && (bool) preg_match('/^[A-Za-z0-9_.\-:]+$/', $key);
    }
}
