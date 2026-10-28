<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\communication\services\ServiceGateway;
use linhub\host\services\llmgateway\LlmModelUnavailableException;
use linhub\host\services\llmgateway\LlmProviderException;
use linhub\host\services\llmgateway\LlmQuotaExceededException;
use linhub\host\services\support\PlatformStore;

/**
 * LLM 调试台（T11）—— admin 应用，仅超管
 *
 *  - GET  /admin/llm_debug_admin/index   调试台页面
 *  - GET  /admin/llm_debug_admin/models   可用模型列表 JSON（is_enabled=1）
 *  - POST /admin/llm_debug_admin/chat    同步 chat 调用（超管 caller='admin.debug'）
 *  - POST /admin/llm_debug_admin/embed   embed 调用
 *
 * 用途：超管快速验证 provider 连通性、模型配置、配额是否正常。不开放 SSE（避免和前端调试耦合）。
 * 调试调用固定使用 caller='admin.debug'，便于审计与配额隔离。
 */
class LlmDebugAdmin extends AdminController
{
    private const DEBUG_CALLER = 'admin.debug';

    private function gateway(): object
    {
        // 统一走 ServiceGateway：既拿到服务实例，也自动计入服务调用统计
        return ServiceGateway::resolveService($this->app, 'llmgateway');
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('llm_debug_admin/index');
    }

    /** JSON：可用模型（is_enabled=1）列表。 */
    public function models()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $store = new PlatformStore();
            $rows = $store->db()->query(
                'SELECT code, provider, model_name, capability, max_tokens'
                . ' FROM ' . PlatformStore::LLM_MODEL_TABLE
                . ' WHERE is_enabled = 1 ORDER BY code ASC'
            );
            return $this->jsonOk(array_map(static function (array $r): array {
                return [
                    'code'       => (string) $r['code'],
                    'provider'   => (string) $r['provider'],
                    'model_name' => (string) $r['model_name'],
                    'capability' => (string) $r['capability'],
                    'max_tokens' => (int) $r['max_tokens'],
                ];
            }, $rows));
        } catch (\Throwable $e) {
            return $this->jsonFail('读取模型列表失败：' . $e->getMessage());
        }
    }

    /** POST：同步 chat 调试调用。 */
    public function chat()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $model = trim((string) $req->post('model', ''));
        $prompt = trim((string) $req->post('prompt', ''));
        if ($model === '' || $prompt === '') {
            return $this->jsonFail('model 与 prompt 为必填');
        }
        $temperature = $req->post('temperature', null);
        $maxTokens   = $req->post('max_tokens', null);

        try {
            $chatReq = new \linhub\host\services\llmgateway\ChatRequest(
                self::DEBUG_CALLER,
                $model,
                [['role' => 'user', 'content' => $prompt]],
                $temperature !== null && $temperature !== '' ? (float) $temperature : null,
                $maxTokens !== null && $maxTokens !== '' ? (int) $maxTokens : null
            );
            $resp = $this->gateway()->chat($chatReq);
            return $this->jsonOk([
                'content'    => $resp->content,
                'finish_reason' => $resp->finish_reason,
                'usage'      => $resp->usage,
                'model'      => $resp->model,
                'call_id'    => $resp->call_id,
            ]);
        } catch (LlmModelUnavailableException | LlmQuotaExceededException | LlmProviderException $e) {
            return $this->jsonFail($e->getMessage(), $this->exceptionCode($e));
        } catch (\Throwable $e) {
            return $this->jsonFail('调试失败：' . $e->getMessage(), 500);
        }
    }

    /** POST：embed 调试调用。 */
    public function embed()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $model = trim((string) $req->post('model', ''));
        $inputRaw = trim((string) $req->post('input', ''));
        if ($model === '' || $inputRaw === '') {
            return $this->jsonFail('model 与 input 为必填');
        }
        // input 以 \n 切分多行
        $inputList = array_values(array_filter(array_map('trim', explode("\n", $inputRaw)), function ($s) { return $s !== ''; }));
        if (!$inputList) {
            return $this->jsonFail('input 解析为空');
        }
        try {
            $embedReq = new \linhub\host\services\llmgateway\EmbedRequest(
                self::DEBUG_CALLER,
                $model,
                $inputList
            );
            $resp = $this->gateway()->embed($embedReq);
            // 不返回完整向量（太长），返回向量维度统计即可
            $dim = isset($resp->vectors[0]) && is_array($resp->vectors[0]) ? count($resp->vectors[0]) : 0;
            return $this->jsonOk([
                'count'     => count($resp->vectors),
                'dimension' => $dim,
                'usage'     => $resp->usage,
                'model'     => $resp->model,
                'call_id'   => $resp->call_id,
            ]);
        } catch (LlmModelUnavailableException | LlmQuotaExceededException | LlmProviderException $e) {
            return $this->jsonFail($e->getMessage(), $this->exceptionCode($e));
        } catch (\Throwable $e) {
            return $this->jsonFail('调试失败：' . $e->getMessage(), 500);
        }
    }

    private function exceptionCode(\Throwable $e): int
    {
        if ($e instanceof LlmQuotaExceededException) {
            return 429;
        }
        if ($e instanceof LlmModelUnavailableException) {
            return 404;
        }
        if ($e instanceof LlmProviderException) {
            return 502;
        }
        return 500;
    }
}
