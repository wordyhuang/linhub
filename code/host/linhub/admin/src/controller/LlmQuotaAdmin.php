<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\support\PlatformStore;

/**
 * LLM caller 配额管理（T12）—— admin 应用，仅超管
 *
 *  - GET  /admin/llm_quota_admin/index   配额列表页
 *  - GET  /admin/llm_quota_admin/rows    分页检索 JSON（caller/model_code）
 *  - POST /admin/llm_quota_admin/save    upsert：按 (caller, model_code) 唯一键
 *  - POST /admin/llm_quota_admin/delete 物理删除该配额记录（回到不限）
 *
 * 表：lg_mgt_llm_caller_quota。daily_token_limit=0 表示不限（由 LlmQuotaGuard 判定）。
 */
class LlmQuotaAdmin extends AdminController
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
        return $this->app->view->fetch('llm_quota_admin/index');
    }

    /** JSON：分页检索。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $caller     = trim((string) $req->get('caller', ''));
        $modelCode  = trim((string) $req->get('model_code', ''));

        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        $store = $this->store();
        $where = ['1=1'];
        if ($caller !== '') {
            $where[] = 'caller LIKE ' . $store->quote('%' . $caller . '%');
        }
        if ($modelCode !== '') {
            $where[] = 'model_code = ' . $store->quote($modelCode);
        }
        $whereSql = implode(' AND ', $where);

        $totalRows = $store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE . ' WHERE ' . $whereSql
        );
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $offset = ($page - 1) * $size;
        $rows = $store->db()->query(
            'SELECT * FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
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

    /** upsert：按 (caller, model_code) 唯一键插入或更新。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $caller    = trim((string) $req->post('caller', ''));
        $modelCode = trim((string) $req->post('model_code', ''));
        $limit     = (int) $req->post('daily_token_limit', 0);

        if ($caller === '' || $modelCode === '') {
            return $this->jsonFail('caller 与 model_code 为必填');
        }
        if ($limit < 0) {
            return $this->jsonFail('daily_token_limit 不能为负数（0 表示不限）');
        }

        $store = $this->store();
        $now = date('c');

        // 校验模型存在
        $modelRows = $store->db()->query(
            'SELECT id FROM ' . PlatformStore::LLM_MODEL_TABLE
            . ' WHERE code = ' . $store->quote($modelCode) . ' LIMIT 1'
        );
        if (!$modelRows) {
            return $this->jsonFail('模型 code 不存在：' . $modelCode);
        }

        // 读已存在记录
        $existing = $store->db()->query(
            'SELECT id FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
            . ' WHERE caller = ' . $store->quote($caller)
            . ' AND model_code = ' . $store->quote($modelCode)
            . ' LIMIT 1'
        );

        try {
            if (!$existing) {
                $store->db()->exec(
                    'INSERT INTO ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
                    . ' (caller, model_code, daily_token_limit, created_at, updated_at)'
                    . ' VALUES ('
                    . $store->quote($caller) . ', '
                    . $store->quote($modelCode) . ', '
                    . $limit . ', '
                    . $store->quote($now) . ', '
                    . $store->quote($now) . ')'
                );
                $action = 'llm_quota.create';
            } else {
                $store->db()->exec(
                    'UPDATE ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
                    . ' SET daily_token_limit = ' . $limit
                    . ', updated_at = ' . $store->quote($now)
                    . ' WHERE id = ' . (int) $existing[0]['id']
                );
                $action = 'llm_quota.update';
            }
            $this->audit($gate, $action, $caller . '/' . $modelCode);
            return $this->jsonOk(null, '配额已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 物理删除（回到不限）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $caller    = trim((string) $this->request->post('caller', ''));
        $modelCode = trim((string) $this->request->post('model_code', ''));
        if ($caller === '' || $modelCode === '') {
            return $this->jsonFail('缺少 caller 或 model_code');
        }
        $store = $this->store();
        $store->db()->exec(
            'DELETE FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
            . ' WHERE caller = ' . $store->quote($caller)
            . ' AND model_code = ' . $store->quote($modelCode)
        );
        $this->audit($gate, 'llm_quota.delete', $caller . '/' . $modelCode);
        return $this->jsonOk(null, '配额已删除（该 caller 恢复不限）');
    }

    /** JSON：模型 code 选项（用于保存下拉）。 */
    public function modelOptions()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $rows = $this->store()->db()->query(
            'SELECT code, model_name FROM ' . PlatformStore::LLM_MODEL_TABLE
            . ' ORDER BY code ASC'
        );
        return $this->jsonOk(array_map(static function (array $r): array {
            return [
                'code'       => (string) $r['code'],
                'model_name' => (string) $r['model_name'],
            ];
        }, $rows));
    }

    // ---------- 内部 ----------

    private function normalizeRow(array $row): array
    {
        return [
            'id'                => (int) ($row['id'] ?? 0),
            'caller'            => (string) ($row['caller'] ?? ''),
            'model_code'        => (string) ($row['model_code'] ?? ''),
            'daily_token_limit' => (int) ($row['daily_token_limit'] ?? 0),
            'created_at'        => (string) ($row['created_at'] ?? ''),
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
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
