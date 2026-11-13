<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 单据打印模板 数据随包驱动（T-8 拍板：包级模板纳入数据随包导出与导入）。
 *
 * 范围与口径（按平台「服务数据随包导出与导入方案」）：
 *  - 仅包级私有模板（scope=package 且 owner_pkg=本包短名）随包；平台公共模板不随包（4.3/边界）；
 *  - scope=seed：仅导出「启用中」模板；scope=all：导出全部（含停用）；
 *  - 版本快照与打印票据属运行态产物，不随包；
 *  - import 归属强制 $packageKey（scope 强制 package），不信任导出文件归属字段；
 *    导入即接管：孤儿冻结标记一并清除（AC-7/AC-11）；
 *  - purge 仅清本包私有模板、其版本快照与票据。
 */
final class PrintTplDataDriver implements ServiceDataDriver
{
    public function capabilities(): array
    {
        return ['scopes' => ['seed', 'all'], 'has_runtime' => false];
    }

    public function export(string $packageKey, string $scope, ?array $range, int $limit): array
    {
        $svc = PrintTplService::instance();
        $st = $svc->store();
        $where = 'owner_pkg = ' . $st->quote($packageKey) . " AND scope = 'package'";
        if ($scope === 'seed') {
            $where .= ' AND status = 1';
        }
        $rows = $st->db()->query(
            'SELECT code, name, scope, owner_pkg, status, mode, paper, body, body_size, sample_data, created_by, created_at, updated_at'
            . ' FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE ' . $where . ' ORDER BY id LIMIT ' . max(1, $limit)
        ) ?? [];
        $truncated = count($rows) >= max(1, $limit);
        return ['rows' => $rows, 'truncated' => $truncated];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $svc = PrintTplService::instance();
        $st = $svc->store();
        if ($mode === 'replace') {
            $svc->purgePackage($packageKey);
        }
        $n = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['code']) || (string) $row['code'] === '') {
                continue;
            }
            $code = (string) $row['code'];
            $now = date('c');
            $paper = is_array($row['paper'] ?? null) ? json_encode($row['paper'], JSON_UNESCAPED_UNICODE) : (string) ($row['paper'] ?? '{}');
            $sample = is_array($row['sample_data'] ?? null) ? json_encode($row['sample_data'], JSON_UNESCAPED_UNICODE) : (string) ($row['sample_data'] ?? '[]');
            $status = (int) ($row['status'] ?? 1) === 1 ? 1 : 0;
            $modeF = in_array(($row['mode'] ?? 'visual'), ['visual', 'source'], true) ? (string) $row['mode'] : 'visual';
            $body = (string) ($row['body'] ?? '');
            $bodySize = (int) ($row['body_size'] ?? strlen($body));

            // 归属强制本包短名；导入即接管（清孤儿标记）
            $exist = $svc->store()->db()->query(
                'SELECT id FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE code = ' . $st->quote($code) . ' LIMIT 1'
            )[0] ?? null;
            if ($exist !== null) {
                $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE . ' SET '
                    . 'name = ' . $st->quote((string) ($row['name'] ?? $code)) . ', '
                    . "scope = 'package', "
                    . 'owner_pkg = ' . $st->quote($packageKey) . ', '
                    . 'status = ' . $status . ', '
                    . 'mode = ' . $st->quote($modeF) . ', '
                    . 'paper = ' . $st->quote($paper) . ', '
                    . 'body = ' . $st->quote($body) . ', '
                    . 'body_size = ' . $st->int($bodySize) . ', '
                    . 'sample_data = ' . $st->quote($sample) . ', '
                    . "orphaned_at = '', "
                    . 'updated_at = ' . $st->quote($now)
                    . ' WHERE id = ' . $st->int((int) $exist['id']));
            } else {
                $st->db()->exec('INSERT INTO ' . PlatformStore::PRINT_TPL_TABLE
                    . ' (code, name, scope, owner_pkg, status, mode, paper, body, body_size, sample_data, created_by, created_at, updated_at) VALUES ('
                    . $st->quote($code) . ', ' . $st->quote((string) ($row['name'] ?? $code)) . ", 'package', "
                    . $st->quote($packageKey) . ', ' . $status . ', ' . $st->quote($modeF) . ', '
                    . $st->quote($paper) . ', ' . $st->quote($body) . ', ' . $st->int($bodySize) . ', '
                    . $st->quote($sample) . ', ' . $st->quote((string) ($row['created_by'] ?? $packageKey)) . ', '
                    . $st->quote($now) . ', ' . $st->quote($now) . ')');
            }
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $svc = PrintTplService::instance();
        $before = $svc->counts()['templates'] ?? 0;
        $svc->purgePackage($packageKey);
        $after = $svc->counts()['templates'] ?? 0;
        return max(0, $before - $after);
    }
}
