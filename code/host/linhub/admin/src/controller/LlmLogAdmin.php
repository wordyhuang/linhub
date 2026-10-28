<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\support\PlatformStore;

/**
 * LLM 调用日志查询（T10）—— admin 应用，仅超管，只读
 *
 *  - GET  /admin/llm_log_admin/index   日志检索页
 *  - GET  /admin/llm_log_admin/rows    分页检索 JSON（caller/model/status/type/date）
 *  - GET  /admin/llm_log_admin/detail   单条日志详情（含完整 prompt/completion）
 *  - GET  /admin/llm_log_admin/stats    今日用量汇总（模型级 / caller 级）
 *
 * 表：lg_mgt_llm_call_log。只读排障，不承载任何写操作。
 */
class LlmLogAdmin extends AdminController
{
    private function store(): PlatformStore
    {
        return new PlatformStore();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('llm_log_admin/index');
    }

    /** JSON：日志分页检索。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['caller', 'model_code', 'provider', 'status', 'type', 'call_id'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $dateFrom = trim((string) $req->get('date_from', ''));
        $dateTo   = trim((string) $req->get('date_to', ''));

        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        $store = $this->store();
        $where = ['1=1'];
        if (!empty($filter['caller'])) {
            $where[] = '(caller_effective LIKE ' . $store->quote('%' . $filter['caller'] . '%')
                . ' OR caller_declared LIKE ' . $store->quote('%' . $filter['caller'] . '%') . ')';
        }
        if (!empty($filter['model_code'])) {
            $where[] = 'model_code = ' . $store->quote($filter['model_code']);
        }
        if (!empty($filter['provider'])) {
            $where[] = 'provider = ' . $store->quote($filter['provider']);
        }
        if (!empty($filter['status'])) {
            $where[] = 'status = ' . $store->quote($filter['status']);
        }
        if (!empty($filter['type'])) {
            $where[] = 'type = ' . $store->quote($filter['type']);
        }
        if (!empty($filter['call_id'])) {
            $where[] = 'call_id = ' . $store->quote($filter['call_id']);
        }
        if ($dateFrom !== '') {
            $where[] = 'created_at >= ' . $store->quote($dateFrom);
        }
        if ($dateTo !== '') {
            $where[] = 'created_at <= ' . $store->quote($dateTo . ' 23:59:59');
        }
        $whereSql = implode(' AND ', $where);

        $totalRows = $store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::LLM_CALL_LOG_TABLE . ' WHERE ' . $whereSql
        );
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $offset = ($page - 1) * $size;
        // 列表不查 prompt/completion 大字段，避免拖慢列表
        $rows = $store->db()->query(
            'SELECT id, call_id, caller_effective, caller_declared, model_code, provider, type, status,'
            . ' prompt_tokens, completion_tokens, usage_estimated, duration_ms, cost_input, cost_output,'
            . ' error_summary, created_at'
            . ' FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE ' . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset
        );

        return $this->jsonOk([
            'list'        => array_map([$this, 'normalizeRow'], $rows),
            'total'       => $total,
            'currentPage' => $page,
            'pageSize'    => $size,
        ]);
    }

    /** JSON：单条日志详情（含完整 prompt/completion）。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $callId = trim((string) $this->request->get('call_id', ''));
        if ($callId === '') {
            return $this->jsonFail('缺少 call_id');
        }
        $rows = $this->store()->db()->query(
            'SELECT * FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE call_id = ' . $this->store()->quote($callId) . ' LIMIT 1'
        );
        if (!$rows) {
            return $this->jsonFail('日志不存在', 404);
        }
        $row = $this->normalizeRow($rows[0]);
        // 详情字段补齐大文本
        $row['prompt_text'] = (string) ($rows[0]['prompt_text'] ?? '');
        $row['completion_text'] = (string) ($rows[0]['completion_text'] ?? '');
        return $this->jsonOk($row);
    }

    /** JSON：今日用量汇总（模型级 / caller 级 Top）。 */
    public function stats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $store = $this->store();
        $today = (new \DateTimeImmutable('today 00:00:00'))->format('c');
        $todaySql = $store->quote($today);

        // 模型级：今日按模型聚合 token
        $byModel = $store->db()->query(
            'SELECT model_code, provider,'
            . ' COUNT(*) AS calls,'
            . ' COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,'
            . ' COALESCE(SUM(completion_tokens),0) AS completion_tokens,'
            . ' COALESCE(SUM(usage_estimated),0) AS total_tokens,'
            . ' COALESCE(SUM(cost_input),0) AS cost_input,'
            . ' COALESCE(SUM(cost_output),0) AS cost_output'
            . ' FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE created_at >= ' . $todaySql
            . ' GROUP BY model_code, provider'
            . ' ORDER BY total_tokens DESC LIMIT 20'
        );

        // caller 级：今日按 caller 聚合（Top 20）
        $byCaller = $store->db()->query(
            'SELECT caller_effective,'
            . ' COUNT(*) AS calls,'
            . ' COALESCE(SUM(usage_estimated),0) AS total_tokens'
            . ' FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE created_at >= ' . $todaySql
            . ' GROUP BY caller_effective'
            . ' ORDER BY total_tokens DESC LIMIT 20'
        );

        // 状态汇总：今日
        $statusRows = $store->db()->query(
            'SELECT status, COUNT(*) AS c FROM ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE created_at >= ' . $todaySql
            . ' GROUP BY status'
        );
        $statusMap = [];
        foreach ($statusRows as $r) {
            $statusMap[(string) $r['status']] = (int) $r['c'];
        }

        return $this->jsonOk([
            'today' => $today,
            'byModel'  => array_map(static function (array $r): array {
                return [
                    'model_code'       => (string) $r['model_code'],
                    'provider'         => (string) $r['provider'],
                    'calls'            => (int) $r['calls'],
                    'prompt_tokens'     => (int) $r['prompt_tokens'],
                    'completion_tokens'=> (int) $r['completion_tokens'],
                    'total_tokens'      => (int) $r['total_tokens'],
                    'cost_input'        => (float) $r['cost_input'],
                    'cost_output'       => (float) $r['cost_output'],
                ];
            }, $byModel),
            'byCaller' => array_map(static function (array $r): array {
                return [
                    'caller_effective' => (string) $r['caller_effective'],
                    'calls'            => (int) $r['calls'],
                    'total_tokens'      => (int) $r['total_tokens'],
                ];
            }, $byCaller),
            'status' => $statusMap,
        ]);
    }

    // ---------- 内部 ----------

    private function normalizeRow(array $row): array
    {
        return [
            'id'                => (int) ($row['id'] ?? 0),
            'call_id'           => (string) ($row['call_id'] ?? ''),
            'caller_effective' => (string) ($row['caller_effective'] ?? ''),
            'caller_declared'   => isset($row['caller_declared']) && $row['caller_declared'] !== null
                ? (string) $row['caller_declared']
                : null,
            'model_code'        => (string) ($row['model_code'] ?? ''),
            'provider'          => (string) ($row['provider'] ?? ''),
            'type'              => (string) ($row['type'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'prompt_tokens'     => isset($row['prompt_tokens']) && $row['prompt_tokens'] !== null ? (int) $row['prompt_tokens'] : null,
            'completion_tokens' => isset($row['completion_tokens']) && $row['completion_tokens'] !== null ? (int) $row['completion_tokens'] : null,
            'usage_estimated'   => (int) ($row['usage_estimated'] ?? 0),
            'duration_ms'       => isset($row['duration_ms']) && $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'cost_input'        => isset($row['cost_input']) && $row['cost_input'] !== null ? (float) $row['cost_input'] : null,
            'cost_output'       => isset($row['cost_output']) && $row['cost_output'] !== null ? (float) $row['cost_output'] : null,
            'error_summary'     => (string) ($row['error_summary'] ?? ''),
            'created_at'        => (string) ($row['created_at'] ?? ''),
        ];
    }
}
