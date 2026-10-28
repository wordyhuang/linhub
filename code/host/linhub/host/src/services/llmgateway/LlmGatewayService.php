<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

use linhub\host\services\llmgateway\Provider\OpenAICompatibleClient;
use linhub\host\services\llmgateway\Support\LlmCallLogger;
use linhub\host\services\llmgateway\Support\LlmQuotaGuard;
use linhub\host\services\support\PlatformStore;
use think\facade\Env;

/**
 * LLM 网关服务门面实现。
 *
 * 编排顺序（每次调用）：
 *   1. 解析 caller（显式传入优先，否则由 BoundLlmGateway 装饰器注入）
 *   2. 从 lg_mgt_llm_model 读取模型实例行
 *   3. 校验启用状态 + 配额
 *   4. 根据 provider 名构造对应 OpenAI 兼容客户端
 *   5. 调用 provider
 *   6. 写调用日志（成功/失败/配额超限）
 *
 * Provider 配置通过 env 注入：
 *   LLM_PROVIDER_<NAME>_BASE_URL
 *   LLM_PROVIDER_<NAME>_API_KEY
 *   LLM_PROVIDER_<NAME>_TIMEOUT_CONNECT (默认 5)
 *   LLM_PROVIDER_<NAME>_TIMEOUT_READ    (默认 60)
 *   LLM_GATEWAY_ENABLED                 (默认 1，置 0 时所有调用抛异常)
 */
final class LlmGatewayService implements LlmGateway
{
    private PlatformStore $store;
    private LlmCallLogger $logger;
    private LlmQuotaGuard $quota;

    /** provider 名 → 客户端实例缓存（按请求生命周期）。 */
    /** @var array<string, OpenAICompatibleClient> */
    private array $clientCache = [];

    public function __construct(?PlatformStore $store = null)
    {
        $this->store  = $store ?? new PlatformStore();
        $this->logger  = new LlmCallLogger($this->store);
        $this->quota   = new LlmQuotaGuard($this->store);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->assertEnabled();
        $caller = $this->resolveCaller($request->caller);
        $modelRow = $this->loadModelOrThrow($request->model);
        $this->assertCapability($modelRow, 'chat');
        $this->quota->assertAvailable($caller, $modelRow);

        $callId = $this->logger->begin(
            $caller,
            $request->caller !== '' ? $request->caller : null,
            (string) $modelRow['code'],
            (string) $modelRow['provider'],
            LlmCallLogger::TYPE_CHAT
        );

        $started = microtime(true);
        try {
            $client = $this->clientFor($modelRow);
            $temperature = $request->temperature ?? (float) ($modelRow['temperature_default'] ?? 0.7);
            $maxTokens   = $request->max_tokens  ?? (int) ($modelRow['max_tokens'] ?? 4096);

            $result = $client->chat($request, (string) $modelRow['model_name'], $temperature, $maxTokens);
            $durationMs = (int) ((microtime(true) - $started) * 1000);

            $usage = $result['usage'];
            $this->logger->update($callId, [
                'status'            => LlmCallLogger::STATUS_SUCCESS,
                'prompt_text'      => $this->joinPrompt($request->messages),
                'completion_text'  => $result['content'],
                'prompt_tokens'    => $usage['prompt_tokens'] ?? null,
                'completion_tokens'=> $usage['completion_tokens'] ?? null,
                'usage_estimated'  => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
                'duration_ms'       => $durationMs,
                'cost_input'        => $this->estimateCost($modelRow, $usage['prompt_tokens'] ?? 0, true),
                'cost_output'       => $this->estimateCost($modelRow, $usage['completion_tokens'] ?? 0, false),
            ]);

            return new ChatResponse(
                content: $result['content'],
                finish_reason: $result['finish_reason'],
                usage: $usage,
                model: (string) $modelRow['code'],
                call_id: $callId,
            );
        } catch (LlmQuotaExceededException $e) {
            $this->logger->update($callId, [
                'status'         => LlmCallLogger::STATUS_QUOTA_EXCEEDED,
                'error_summary'  => $e->getMessage(),
                'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->update($callId, [
                'status'         => LlmCallLogger::STATUS_FAILED,
                'error_summary'  => $e->getMessage(),
                'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public function chatStream(ChatRequest $request): \Generator
    {
        $this->assertEnabled();
        $caller = $this->resolveCaller($request->caller);
        $modelRow = $this->loadModelOrThrow($request->model);
        $this->assertCapability($modelRow, 'chat'); // 流式只是同一模型的传输方式
        $this->quota->assertAvailable($caller, $modelRow);

        $callId = $this->logger->begin(
            $caller,
            $request->caller !== '' ? $request->caller : null,
            (string) $modelRow['code'],
            (string) $modelRow['provider'],
            LlmCallLogger::TYPE_STREAM
        );

        $started = microtime(true);
        $full = '';
        $usage = null;
        $finishReason = null;
        try {
            $client = $this->clientFor($modelRow);
            $temperature = $request->temperature ?? (float) ($modelRow['temperature_default'] ?? 0.7);
            $maxTokens   = $request->max_tokens  ?? (int) ($modelRow['max_tokens'] ?? 4096);

            foreach ($client->chatStream($request, (string) $modelRow['model_name'], $temperature, $maxTokens) as $chunk) {
                if (!empty($chunk['delta'])) {
                    $full .= $chunk['delta'];
                }
                if (isset($chunk['finish_reason'])) {
                    $finishReason = $chunk['finish_reason'];
                }
                if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage = $chunk['usage'];
                }
                yield $chunk;
            }

            $durationMs = (int) ((microtime(true) - $started) * 1000);
            $this->logger->update($callId, [
                'status'            => LlmCallLogger::STATUS_SUCCESS,
                'prompt_text'      => $this->joinPrompt($request->messages),
                'completion_text'  => $full,
                'prompt_tokens'     => $usage['prompt_tokens'] ?? null,
                'completion_tokens'=> $usage['completion_tokens'] ?? null,
                'usage_estimated'  => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
                'duration_ms'       => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $this->logger->update($callId, [
                'status'         => LlmCallLogger::STATUS_FAILED,
                'error_summary'  => $e->getMessage(),
                'completion_text' => $full !== '' ? $full : null,
                'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        $this->assertEnabled();
        $caller = $this->resolveCaller($request->caller);
        $modelRow = $this->loadModelOrThrow($request->model);
        $this->assertCapability($modelRow, 'embedding');
        $this->quota->assertAvailable($caller, $modelRow);

        $callId = $this->logger->begin(
            $caller,
            $request->caller !== '' ? $request->caller : null,
            (string) $modelRow['code'],
            (string) $modelRow['provider'],
            LlmCallLogger::TYPE_EMBED
        );

        $started = microtime(true);
        try {
            $client = $this->clientFor($modelRow);
            $result = $client->embed($request, (string) $modelRow['model_name']);
            $durationMs = (int) ((microtime(true) - $started) * 1000);

            $usage = $result['usage'];
            $this->logger->update($callId, [
                'status'           => LlmCallLogger::STATUS_SUCCESS,
                'prompt_text'      => implode("\n", array_slice($request->inputList(), 0, 20)),
                'prompt_tokens'    => $usage['prompt_tokens'] ?? null,
                'completion_tokens'=> 0,
                'usage_estimated'  => $usage['prompt_tokens'] ?? 0,
                'duration_ms'      => $durationMs,
            ]);

            return new EmbedResponse(
                vectors: $result['vectors'],
                usage: $usage,
                model: (string) $modelRow['code'],
                call_id: $callId,
            );
        } catch (\Throwable $e) {
            $this->logger->update($callId, [
                'status'        => LlmCallLogger::STATUS_FAILED,
                'error_summary' => $e->getMessage(),
                'duration_ms'   => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public function listModels(?string $capability = null): array
    {
        $sql = 'SELECT code, provider, model_name, capability, max_tokens FROM '
            . PlatformStore::LLM_MODEL_TABLE . ' WHERE is_enabled = 1';
        $params = [];
        if ($capability !== null) {
            $sql .= ' AND capability = ' . $this->store->quote($capability);
        }
        $sql .= ' ORDER BY code ASC';
        $rows = $this->store->db()->query($sql);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'code'         => (string) $r['code'],
                'provider'     => (string) $r['provider'],
                'model_name'   => (string) $r['model_name'],
                'capability'   => (string) $r['capability'],
                'max_tokens'   => (int) $r['max_tokens'],
            ];
        }
        return $out;
    }

    public function getModel(string $code): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::LLM_MODEL_TABLE
            . ' WHERE code = ' . $this->store->quote($code) . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    // ---------- 内部 ----------

    private function assertEnabled(): void
    {
        if (((string) Env::get('LLM_GATEWAY_ENABLED', '1')) !== '1') {
            throw new LlmProviderException('LLM 网关已被 LLM_GATEWAY_ENABLED=0 关闭');
        }
    }

    private function resolveCaller(string $declared): string
    {
        $declared = trim($declared);
        if ($declared === '') {
            throw new \InvalidArgumentException(
                'caller 未指定：请经 ServiceInjector 拿到 BoundLlmGateway 实例，或在请求中显式传 caller'
            );
        }
        return $declared;
    }

    private function loadModelOrThrow(string $code): array
    {
        $row = $this->getModel($code);
        if ($row === null) {
            throw new LlmModelUnavailableException("模型实例 {$code} 不存在");
        }
        if ((int) ($row['is_enabled'] ?? 0) !== 1) {
            throw new LlmModelUnavailableException("模型实例 {$code} 已被禁用");
        }
        return $row;
    }

    private function assertCapability(array $modelRow, string $expected): void
    {
        $cap = (string) ($modelRow['capability'] ?? '');
        if ($expected === 'chat' && ($cap === 'chat' || $cap === 'chat_stream')) {
            return;
        }
        if ($cap === $expected) {
            return;
        }
        throw new \InvalidArgumentException(
            "模型 {$modelRow['code']} 能力为 {$cap}，不支持 {$expected}"
        );
    }

    /**
     * 按 provider 名从 env 构造客户端。
     *
     * env key 规则：LLM_PROVIDER_<NAME>_BASE_URL / LLM_PROVIDER_<NAME>_API_KEY
     */
    private function clientFor(array $modelRow): OpenAICompatibleClient
    {
        $provider = strtoupper((string) $modelRow['provider']);
        if (isset($this->clientCache[$provider])) {
            return $this->clientCache[$provider];
        }
        $baseUrl = (string) Env::get("LLM_PROVIDER_{$provider}_BASE_URL", '');
        $apiKey  = (string) Env::get("LLM_PROVIDER_{$provider}_API_KEY", '');
        if ($baseUrl === '' || $apiKey === '') {
            throw new LlmProviderException(
                "provider {$provider} 未配置 env：需要 LLM_PROVIDER_{$provider}_BASE_URL 与 LLM_PROVIDER_{$provider}_API_KEY"
            );
        }
        $client = new OpenAICompatibleClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            timeoutConnect: (int) Env::get("LLM_PROVIDER_{$provider}_TIMEOUT_CONNECT", '5'),
            timeoutRead:    (int) Env::get("LLM_PROVIDER_{$provider}_TIMEOUT_READ", '60'),
        );
        return $this->clientCache[$provider] = $client;
    }

    private function joinPrompt(array $messages): string
    {
        $out = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            $out[] = "[{$role}] {$content}";
        }
        return implode("\n", $out);
    }

    private function estimateCost(array $modelRow, int $tokens, bool $isInput): ?float
    {
        $unit = $isInput
            ? ($modelRow['unit_cost_input'] ?? null)
            : ($modelRow['unit_cost_output'] ?? null);
        if ($unit === null || $unit === '') {
            return null;
        }
        return round((float) $unit * $tokens, 6);
    }
}
