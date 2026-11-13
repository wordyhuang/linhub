<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * 审批服务 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1 / 8）
 *
 * 数据形态：lg_mgt_appr_flow 行集（package = 包 short）。
 * scope 语义：
 *  - seed / all：导出该包名下全部流程模板（DSL 定义），二者内容一致
 *    （模板为配置数据，无运行态增量；审批实例属运行态，本版不导出，
 *    由卸载孤儿冻结与重装接管机制托管）
 *  - range：不支持
 *
 * 导入按 (package, flow_key) upsert：已存在则更新定义并将 version 自增
 * （与 ApprovalService.defineFlow 的版本语义一致）；replace 先清空本包模板。
 * purge 仅清模板，不触碰审批实例。
 */
final class ApprovalDataDriver implements ServiceDataDriver
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
        $this->store->ensureApprovalTables();
        $limit = max(1, $limit);
        $rows = $this->store->db()->query(
            'SELECT flow_key, name, definition, version, status, source, created_at, updated_at'
            . ' FROM ' . PlatformStore::APPR_FLOW_TABLE
            . ' WHERE package=' . $this->store->quote($packageKey)
            . ' ORDER BY flow_key LIMIT ' . ($limit + 1)
        );
        $truncated = count($rows) > $limit;
        return [
            'rows'      => $truncated ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => $truncated,
        ];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $this->store->ensureApprovalTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        // replace：先清空该包名下全部流程模板，再整批写入（审批实例不受影响）
        if ($mode === 'replace') {
            $db->exec('DELETE FROM ' . PlatformStore::APPR_FLOW_TABLE . ' WHERE package=' . $ns);
        }
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $flowKey = (string) ($row['flow_key'] ?? '');
            if ($flowKey === '') {
                continue;
            }
            $k = $this->store->quote($flowKey);
            $name = (string) ($row['name'] ?? '');
            $definition = (string) ($row['definition'] ?? '');
            $status = (string) ($row['status'] ?? 'active');
            // 归属强制为 packageKey
            $existing = $db->query(
                'SELECT id, version FROM ' . PlatformStore::APPR_FLOW_TABLE
                . ' WHERE package=' . $ns . ' AND flow_key=' . $k . ' LIMIT 1'
            );
            if ($existing !== []) {
                $db->exec(
                    'UPDATE ' . PlatformStore::APPR_FLOW_TABLE . ' SET '
                    . 'name=' . $this->store->quote($name) . ', '
                    . 'definition=' . $this->store->quote($definition) . ', '
                    . 'version=' . ((int) ($existing[0]['version'] ?? 0) + 1) . ', '
                    . 'status=' . $this->store->quote($status) . ', '
                    . 'updated_at=' . $this->store->quote((string) ($row['updated_at'] ?? date('c')))
                    . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
                );
                $n++;
                continue;
            }
            $source = ($row['source'] ?? '') === 'admin' ? 'admin' : 'package';
            $db->exec(
                'INSERT INTO ' . PlatformStore::APPR_FLOW_TABLE
                . ' (package, flow_key, name, definition, version, status, source, created_at, updated_at) VALUES ('
                . $ns . ', '
                . $k . ', '
                . $this->store->quote($name) . ', '
                . $this->store->quote($definition) . ', '
                . max(1, (int) ($row['version'] ?? 1)) . ', '
                . $this->store->quote($status) . ', '
                . $this->store->quote($source) . ', '
                . $this->store->quote((string) ($row['created_at'] ?? date('c'))) . ', '
                . $this->store->quote((string) ($row['updated_at'] ?? date('c'))) . ')'
            );
            $n++;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $this->store->ensureApprovalTables();
        $db = $this->store->db();
        $ns = $this->store->quote($packageKey);
        $rows = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::APPR_FLOW_TABLE . ' WHERE package=' . $ns
        );
        $db->exec('DELETE FROM ' . PlatformStore::APPR_FLOW_TABLE . ' WHERE package=' . $ns);
        return (int) ($rows[0]['c'] ?? 0);
    }
}
