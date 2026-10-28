<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\support\PlatformStore;

/**
 * LLM 模型实例管理（T9）—— admin 应用，仅超管
 *
 *  - GET  /admin/llm_model_admin/index   模型列表页
 *  - GET  /admin/llm_model_admin/rows     分页检索 JSON（code/provider/capability/is_enabled）
 *  - GET  /admin/llm_model_admin/detail   单条详情
 *  - POST /admin/llm_model_admin/save     新建或更新（按 code 判定；code 不可变）
 *  - POST /admin/llm_model_admin/delete   软禁用（is_enabled=0，不物理删）
 *
 * 表：lg_mgt_llm_model。provider 值走 OpenAI 兼容协议（OpenAI/DeepSeek/Kimi/Qwen/Zhipu 等），
 * 实际 base_url/api_key 由 env LLM_PROVIDER_<NAME>_* 注入，本页不持有密钥。
 */
class LlmModelAdmin extends AdminController
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
        return $this->app->view->fetch('llm_model_admin/index');
    }

    /** JSON：模型分页检索。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['code', 'provider', 'capability'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $enabled = trim((string) $req->get('is_enabled', ''));
        if ($enabled !== '') {
            $filter['is_enabled'] = (int) $enabled;
        }

        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        $store = $this->store();
        $where = ['1=1'];
        $params = [];
        if (!empty($filter['code'])) {
            $where[] = 'code LIKE ' . $store->quote('%' . $filter['code'] . '%');
        }
        if (!empty($filter['provider'])) {
            $where[] = 'provider = ' . $store->quote($filter['provider']);
        }
        if (!empty($filter['capability'])) {
            $where[] = 'capability = ' . $store->quote($filter['capability']);
        }
        if (array_key_exists('is_enabled', $filter)) {
            $where[] = 'is_enabled = ' . (int) $filter['is_enabled'];
        }
        $whereSql = implode(' AND ', $where);

        $totalRows = $store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::LLM_MODEL_TABLE . ' WHERE ' . $whereSql
        );
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $offset = ($page - 1) * $size;
        $rows = $store->db()->query(
            'SELECT * FROM ' . PlatformStore::LLM_MODEL_TABLE
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

    /** JSON：单条详情（含完整字段）。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $code = trim((string) $this->request->get('code', ''));
        if ($code === '') {
            return $this->jsonFail('缺少 code');
        }
        $row = $this->findByCode($code);
        if ($row === null) {
            return $this->jsonFail('模型不存在', 404);
        }
        return $this->jsonOk($this->normalizeRow($row));
    }

    /** 新建或更新：code 作为主键唯一键；更新时忽略 code 字段。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $code = trim((string) $req->post('code', ''));
        $provider = trim((string) $req->post('provider', ''));
        $modelName = trim((string) $req->post('model_name', ''));
        $capability = trim((string) $req->post('capability', 'chat'));
        $maxTokens = (int) $req->post('max_tokens', 4096);
        $temperature = (float) $req->post('temperature_default', 0.7);
        $isEnabled = (int) $req->post('is_enabled', 1);
        $dailyQuota = (int) $req->post('daily_token_quota', 0);
        $unitCostInput = $req->post('unit_cost_input', null);
        $unitCostOutput = $req->post('unit_cost_output', null);
        $remark = trim((string) $req->post('remark', ''));

        if ($code === '' || $provider === '' || $modelName === '') {
            return $this->jsonFail('code / provider / model_name 为必填');
        }
        if (!in_array($capability, ['chat', 'chat_stream', 'embedding'], true)) {
            return $this->jsonFail('capability 仅支持 chat / chat_stream / embedding');
        }

        $store = $this->store();
        $now = date('c');
        $existing = $this->findByCode($code);
        try {
            if ($existing === null) {
                $store->db()->exec(
                    'INSERT INTO ' . PlatformStore::LLM_MODEL_TABLE
                    . ' (code, provider, model_name, capability, max_tokens, temperature_default,'
                    . ' is_enabled, daily_token_quota, unit_cost_input, unit_cost_output, remark, created_at, updated_at)'
                    . ' VALUES ('
                    . $store->quote($code) . ', '
                    . $store->quote($provider) . ', '
                    . $store->quote($modelName) . ', '
                    . $store->quote($capability) . ', '
                    . $maxTokens . ', '
                    . $store->quote(number_format($temperature, 2, '.', '')) . ', '
                    . $isEnabled . ', '
                    . $dailyQuota . ', '
                    . ($unitCostInput !== null && $unitCostInput !== '' ? $store->quote(number_format((float) $unitCostInput, 6, '.', '')) : 'NULL') . ', '
                    . ($unitCostOutput !== null && $unitCostOutput !== '' ? $store->quote(number_format((float) $unitCostOutput, 6, '.', '')) : 'NULL') . ', '
                    . $store->quote($remark) . ', '
                    . $store->quote($now) . ', '
                    . $store->quote($now) . ')'
                );
                $action = 'llm_model.create';
            } else {
                $store->db()->exec(
                    'UPDATE ' . PlatformStore::LLM_MODEL_TABLE . ' SET '
                    . 'provider = ' . $store->quote($provider) . ', '
                    . 'model_name = ' . $store->quote($modelName) . ', '
                    . 'capability = ' . $store->quote($capability) . ', '
                    . 'max_tokens = ' . $maxTokens . ', '
                    . 'temperature_default = ' . $store->quote(number_format($temperature, 2, '.', '')) . ', '
                    . 'is_enabled = ' . $isEnabled . ', '
                    . 'daily_token_quota = ' . $dailyQuota . ', '
                    . 'unit_cost_input = ' . ($unitCostInput !== null && $unitCostInput !== '' ? $store->quote(number_format((float) $unitCostInput, 6, '.', '')) : 'NULL') . ', '
                    . 'unit_cost_output = ' . ($unitCostOutput !== null && $unitCostOutput !== '' ? $store->quote(number_format((float) $unitCostOutput, 6, '.', '')) : 'NULL') . ', '
                    . 'remark = ' . $store->quote($remark) . ', '
                    . 'updated_at = ' . $store->quote($now)
                    . ' WHERE code = ' . $store->quote($code)
                );
                $action = 'llm_model.update';
            }
            $this->audit($gate, $action, $code);
            return $this->jsonOk(null, $existing === null ? '模型已创建' : '模型已更新');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 软禁用：is_enabled = 0（不物理删除，保留历史引用）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $code = trim((string) $this->request->post('code', ''));
        if ($code === '') {
            return $this->jsonFail('缺少 code');
        }
        $store = $this->store();
        if ($this->findByCode($code) === null) {
            return $this->jsonFail('模型不存在', 404);
        }
        $store->db()->exec(
            'UPDATE ' . PlatformStore::LLM_MODEL_TABLE
            . ' SET is_enabled = 0, updated_at = ' . $store->quote(date('c'))
            . ' WHERE code = ' . $store->quote($code)
        );
        $this->audit($gate, 'llm_model.disable', $code);
        return $this->jsonOk(null, '模型已禁用');
    }

    // ---------- 内部 ----------

    private function findByCode(string $code): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::LLM_MODEL_TABLE
            . ' WHERE code = ' . $this->store()->quote($code) . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'code'                 => (string) ($row['code'] ?? ''),
            'provider'             => (string) ($row['provider'] ?? ''),
            'model_name'           => (string) ($row['model_name'] ?? ''),
            'capability'           => (string) ($row['capability'] ?? 'chat'),
            'max_tokens'           => (int) ($row['max_tokens'] ?? 4096),
            'temperature_default'  => isset($row['temperature_default']) ? (float) $row['temperature_default'] : 0.7,
            'is_enabled'           => (int) ($row['is_enabled'] ?? 1) === 1,
            'daily_token_quota'    => (int) ($row['daily_token_quota'] ?? 0),
            'unit_cost_input'      => isset($row['unit_cost_input']) && $row['unit_cost_input'] !== null ? (float) $row['unit_cost_input'] : null,
            'unit_cost_output'     => isset($row['unit_cost_output']) && $row['unit_cost_output'] !== null ? (float) $row['unit_cost_output'] : null,
            'remark'               => (string) ($row['remark'] ?? ''),
            'created_at'           => (string) ($row['created_at'] ?? ''),
            'updated_at'           => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
