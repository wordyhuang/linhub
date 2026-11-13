<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

use linhub\host\services\support\PlatformStore;
use linhub\host\services\support\ServiceDataDriver;

/**
 * LLM 网关 · 数据随包驱动（数据随包导出与导入方案 V1.6 / 7.1 / 8）
 *
 * 本服务托管运行态数据（调用日志），has_runtime=true：未在 manifest
 * data.services 显式声明时默认不参与导出。
 *
 * 数据形态与归属维度：模型实例表为平台全局配置（无包归属）；
 * 调用方配额表与调用日志表以 caller / caller_effective = 包 short 为本包数据。
 * scope 语义：
 *  - seed ：模型实例配置 + 本包调用方配额（配置基线）
 *  - all  ：seed 全部内容 + 本包全部调用日志
 *  - range：仅导出 range.from ~ range.to 窗口内的本包调用日志
 *
 * 脱敏（方案 7.1）：模型表无密钥列，调用日志仅导出审计元数据
 * （tokens/耗时/费用/状态），prompt_text / completion_text 一律不导出。
 * 模型实例不参与 purge 与 replace 清空（平台公共数据），仅按 code upsert；
 * 配额与日志归属强制 caller = packageKey。
 */
final class LlmGatewayDataDriver implements ServiceDataDriver
{
    public function __construct(private readonly PlatformStore $store)
    {
    }

    public function capabilities(): array
    {
        return ['scopes' => ['seed', 'all', 'range'], 'has_runtime' => true];
    }

    public function export(string $packageKey, string $scope, ?array $range, int $limit): array
    {
        $this->store->ensureLlmTables();
        $limit = max(1, $limit);
        $db = $this->store->db();
        $caller = $this->store->quote($packageKey);
        $rows = [];
        if ($scope === 'seed' || $scope === 'all') {
            foreach ($db->query(
                'SELECT code, provider, model_name, capability, max_tokens, temperature_default,'
                . ' is_enabled, daily_token_quota, unit_cost_input, unit_cost_output, remark, created_at, updated_at'
                . ' FROM ' . PlatformStore::LLM_MODEL_TABLE . ' ORDER BY code'
            ) as $r) {
                $r['_kind'] = 'model';
                $rows[] = $r;
            }
            foreach ($db->query(
                'SELECT model_code, daily_token_limit, created_at, updated_at'
                . ' FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
                . ' WHERE caller=' . $caller . ' ORDER BY model_code'
            ) as $r) {
                $r['_kind'] = 'quota';
                $rows[] = $r;
            }
        }
        if ($scope === 'all') {
            foreach ($db->query(
                'SELECT call_id, caller_declared, model_code, provider, type, status,'
                . ' prompt_tokens, completion_tokens, usage_estimated, duration_ms,'
                . ' cost_input, cost_output, error_summary, created_at'
                . ' FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
                . ' WHERE caller_effective=' . $caller . ' ORDER BY created_at'
            ) as $r) {
                $r['_kind'] = 'log';
                $rows[] = $r;
            }
        }
        if ($scope === 'range') {
            [$from, $toEx] = $this->window($range);
            foreach ($db->query(
                'SELECT call_id, caller_declared, model_code, provider, type, status,'
                . ' prompt_tokens, completion_tokens, usage_estimated, duration_ms,'
                . ' cost_input, cost_output, error_summary, created_at'
                . ' FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
                . ' WHERE caller_effective=' . $caller
                . ' AND created_at >= ' . $this->store->quote($from)
                . ' AND created_at < ' . $this->store->quote($toEx)
                . ' ORDER BY created_at'
            ) as $r) {
                $r['_kind'] = 'log';
                $rows[] = $r;
            }
        }
        return [
            'rows'      => count($rows) > $limit ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => count($rows) > $limit,
        ];
    }

    public function import(string $packageKey, array $rows, string $mode): int
    {
        $this->store->ensureLlmTables();
        $db = $this->store->db();
        $caller = $this->store->quote($packageKey);
        // replace：清空本包配额与调用日志（模型实例为平台公共数据，不清）
        if ($mode === 'replace') {
            $db->exec('DELETE FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE . ' WHERE caller=' . $caller);
            $db->exec('DELETE FROM ' . PlatformStore::LLM_CALL_LOG_TABLE . ' WHERE caller_effective=' . $caller);
        }
        $n = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $kind = (string) ($row['_kind']
                ?? (isset($row['call_id']) ? 'log' : (isset($row['code']) ? 'model' : 'quota')));
            $written = match ($kind) {
                'model' => $this->importModel($row),
                'quota' => $this->importQuota($packageKey, $row),
                default => $this->importLog($packageKey, $row),
            };
            $n += $written;
        }
        return $n;
    }

    public function purge(string $packageKey): int
    {
        $this->store->ensureLlmTables();
        $db = $this->store->db();
        $caller = $this->store->quote($packageKey);
        $quotas = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE . ' WHERE caller=' . $caller
        );
        $logs = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::LLM_CALL_LOG_TABLE . ' WHERE caller_effective=' . $caller
        );
        $db->exec('DELETE FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE . ' WHERE caller=' . $caller);
        $db->exec('DELETE FROM ' . PlatformStore::LLM_CALL_LOG_TABLE . ' WHERE caller_effective=' . $caller);
        return (int) ($quotas[0]['c'] ?? 0) + (int) ($logs[0]['c'] ?? 0);
    }

    /** 模型实例按 code upsert（全局配置，永不删除）。 */
    private function importModel(array $row): int
    {
        $code = (string) ($row['code'] ?? '');
        if ($code === '') {
            return 0;
        }
        $db = $this->store->db();
        $c = $this->store->quote($code);
        $vals = [
            'provider'           => (string) ($row['provider'] ?? ''),
            'model_name'         => (string) ($row['model_name'] ?? ''),
            'capability'         => (string) ($row['capability'] ?? 'chat'),
            'max_tokens'         => (int) ($row['max_tokens'] ?? 0),
            'temperature_default' => (string) ($row['temperature_default'] ?? '0.70'),
            'is_enabled'         => (int) (bool) ($row['is_enabled'] ?? 1),
            'daily_token_quota'  => (int) ($row['daily_token_quota'] ?? 0),
            'unit_cost_input'    => (string) ($row['unit_cost_input'] ?? '0'),
            'unit_cost_output'   => (string) ($row['unit_cost_output'] ?? '0'),
            'remark'             => (string) ($row['remark'] ?? ''),
            'created_at'         => (string) ($row['created_at'] ?? date('c')),
            'updated_at'         => (string) ($row['updated_at'] ?? date('c')),
        ];
        $existing = $db->query(
            'SELECT id FROM ' . PlatformStore::LLM_MODEL_TABLE . ' WHERE code=' . $c . ' LIMIT 1'
        );
        if ($existing !== []) {
            $db->exec(
                'UPDATE ' . PlatformStore::LLM_MODEL_TABLE . ' SET '
                . 'provider=' . $this->store->quote($vals['provider']) . ', '
                . 'model_name=' . $this->store->quote($vals['model_name']) . ', '
                . 'capability=' . $this->store->quote($vals['capability']) . ', '
                . 'max_tokens=' . $vals['max_tokens'] . ', '
                . 'temperature_default=' . $this->store->quote($vals['temperature_default']) . ', '
                . 'is_enabled=' . $vals['is_enabled'] . ', '
                . 'daily_token_quota=' . $vals['daily_token_quota'] . ', '
                . 'unit_cost_input=' . $this->store->quote($vals['unit_cost_input']) . ', '
                . 'unit_cost_output=' . $this->store->quote($vals['unit_cost_output']) . ', '
                . 'remark=' . $this->store->quote($vals['remark']) . ', '
                . 'updated_at=' . $this->store->quote($vals['updated_at'])
                . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
            );
            return 1;
        }
        $db->exec(
            'INSERT INTO ' . PlatformStore::LLM_MODEL_TABLE
            . ' (code, provider, model_name, capability, max_tokens, temperature_default, is_enabled,'
            . ' daily_token_quota, unit_cost_input, unit_cost_output, remark, created_at, updated_at) VALUES ('
            . $c . ', '
            . $this->store->quote($vals['provider']) . ', '
            . $this->store->quote($vals['model_name']) . ', '
            . $this->store->quote($vals['capability']) . ', '
            . $vals['max_tokens'] . ', '
            . $this->store->quote($vals['temperature_default']) . ', '
            . $vals['is_enabled'] . ', '
            . $vals['daily_token_quota'] . ', '
            . $this->store->quote($vals['unit_cost_input']) . ', '
            . $this->store->quote($vals['unit_cost_output']) . ', '
            . $this->store->quote($vals['remark']) . ', '
            . $this->store->quote($vals['created_at']) . ', '
            . $this->store->quote($vals['updated_at']) . ')'
        );
        return 1;
    }

    /** 调用方配额按 (caller=packageKey, model_code) upsert，归属强制为本包。 */
    private function importQuota(string $packageKey, array $row): int
    {
        $modelCode = (string) ($row['model_code'] ?? '');
        if ($modelCode === '') {
            return 0;
        }
        $db = $this->store->db();
        $caller = $this->store->quote($packageKey);
        $m = $this->store->quote($modelCode);
        $limit = (int) ($row['daily_token_limit'] ?? 0);
        $existing = $db->query(
            'SELECT id FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
            . ' WHERE caller=' . $caller . ' AND model_code=' . $m . ' LIMIT 1'
        );
        if ($existing !== []) {
            $db->exec(
                'UPDATE ' . PlatformStore::LLM_CALLER_QUOTA_TABLE . ' SET '
                . 'daily_token_limit=' . $limit . ', '
                . 'updated_at=' . $this->store->quote((string) ($row['updated_at'] ?? date('c')))
                . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
            );
            return 1;
        }
        $db->exec(
            'INSERT INTO ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
            . ' (caller, model_code, daily_token_limit, created_at, updated_at) VALUES ('
            . $caller . ', '
            . $m . ', '
            . $limit . ', '
            . $this->store->quote((string) ($row['created_at'] ?? date('c'))) . ', '
            . $this->store->quote((string) ($row['updated_at'] ?? date('c'))) . ')'
        );
        return 1;
    }

    /** 调用日志按 call_id upsert，归属强制 caller_effective = packageKey。 */
    private function importLog(string $packageKey, array $row): int
    {
        $callId = (string) ($row['call_id'] ?? '');
        if ($callId === '') {
            return 0;
        }
        $db = $this->store->db();
        $caller = $this->store->quote($packageKey);
        $cid = $this->store->quote($callId);
        $existing = $db->query(
            'SELECT id FROM ' . PlatformStore::LLM_CALL_LOG_TABLE . ' WHERE call_id=' . $cid . ' LIMIT 1'
        );
        $vals = [
            'caller_declared'  => (string) ($row['caller_declared'] ?? ''),
            'model_code'       => (string) ($row['model_code'] ?? ''),
            'provider'         => (string) ($row['provider'] ?? ''),
            'type'             => (string) ($row['type'] ?? 'chat'),
            'status'           => (string) ($row['status'] ?? 'ok'),
            'prompt_tokens'    => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'usage_estimated'  => (int) (bool) ($row['usage_estimated'] ?? 0),
            'duration_ms'      => (int) ($row['duration_ms'] ?? 0),
            'cost_input'       => (string) ($row['cost_input'] ?? '0'),
            'cost_output'      => (string) ($row['cost_output'] ?? '0'),
            'error_summary'    => (string) ($row['error_summary'] ?? ''),
            'created_at'       => (string) ($row['created_at'] ?? date('c')),
        ];
        if ($existing !== []) {
            $db->exec(
                'UPDATE ' . PlatformStore::LLM_CALL_LOG_TABLE . ' SET '
                . 'caller_declared=' . $this->store->quote($vals['caller_declared']) . ', '
                . 'model_code=' . $this->store->quote($vals['model_code']) . ', '
                . 'provider=' . $this->store->quote($vals['provider']) . ', '
                . 'type=' . $this->store->quote($vals['type']) . ', '
                . 'status=' . $this->store->quote($vals['status']) . ', '
                . 'prompt_tokens=' . $vals['prompt_tokens'] . ', '
                . 'completion_tokens=' . $vals['completion_tokens'] . ', '
                . 'usage_estimated=' . $vals['usage_estimated'] . ', '
                . 'duration_ms=' . $vals['duration_ms'] . ', '
                . 'cost_input=' . $this->store->quote($vals['cost_input']) . ', '
                . 'cost_output=' . $this->store->quote($vals['cost_output']) . ', '
                . 'error_summary=' . $this->store->quote($vals['error_summary']) . ', '
                . 'created_at=' . $this->store->quote($vals['created_at'])
                . ' WHERE id=' . (int) ($existing[0]['id'] ?? 0)
            );
            return 1;
        }
        $db->exec(
            'INSERT INTO ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' (call_id, caller_effective, caller_declared, model_code, provider, type, status,'
            . ' prompt_text, completion_text, prompt_tokens, completion_tokens, usage_estimated,'
            . ' duration_ms, cost_input, cost_output, error_summary, created_at) VALUES ('
            . $cid . ', '
            . $caller . ', '
            . $this->store->quote($vals['caller_declared']) . ', '
            . $this->store->quote($vals['model_code']) . ', '
            . $this->store->quote($vals['provider']) . ', '
            . $this->store->quote($vals['type']) . ', '
            . $this->store->quote($vals['status']) . ", '', '', "
            . $vals['prompt_tokens'] . ', '
            . $vals['completion_tokens'] . ', '
            . $vals['usage_estimated'] . ', '
            . $vals['duration_ms'] . ', '
            . $this->store->quote($vals['cost_input']) . ', '
            . $this->store->quote($vals['cost_output']) . ', '
            . $this->store->quote($vals['error_summary']) . ', '
            . $this->store->quote($vals['created_at']) . ')'
        );
        return 1;
    }

    /** range 窗口归一：返回 [起始日, 结束日次日凌晨（开区间上界）]。 */
    private function window(?array $range): array
    {
        $from = (string) ($range['from'] ?? '');
        $to = (string) ($range['to'] ?? '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '1970-01-01';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '2999-12-31';
        $toEx = date('Y-m-d', strtotime($to . ' +1 day'));
        return [$from, $toEx];
    }
}
