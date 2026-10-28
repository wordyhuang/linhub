<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway\Provider;

use linhub\host\services\llmgateway\ChatRequest;
use linhub\host\services\llmgateway\EmbedRequest;
use linhub\host\services\llmgateway\LlmProviderException;

/**
 * OpenAI 兼容协议客户端。
 *
 * 覆盖 OpenAI / DeepSeek / Kimi / 通义 / 智谱 / 自建 vLLM 等所有遵循
 * POST {base_url}/chat/completions 与 POST {base_url}/embeddings 的服务。
 *
 * 用 cURL 实现，不依赖 Guzzle（与平台现有栈一致）。
 */
final class OpenAICompatibleClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutConnect = 5,
        private readonly int $timeoutRead = 60,
    ) {
    }

    /**
     * 同步 chat。
     *
     * @return array{content:string, finish_reason:string, usage:array}
     * @throws LlmProviderException
     */
    public function chat(ChatRequest $req, string $modelName, ?float $temperature, ?int $maxTokens): array
    {
        $payload = [
            'model'       => $modelName,
            'messages'    => $req->messages,
            'stream'      => false,
        ];
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }
        foreach ($req->extra as $k => $v) {
            $payload[$k] = $v;
        }

        [$httpCode, $body] = $this->httpJson('POST', '/chat/completions', $payload);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw $this->toException($httpCode, $body);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            throw new LlmProviderException("OpenAI 响应格式异常：{$body}");
        }

        return [
            'content'       => (string) $data['choices'][0]['message']['content'],
            'finish_reason' => (string) ($data['choices'][0]['finish_reason'] ?? 'stop'),
            'usage'         => [
                'prompt_tokens'     => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($data['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * 流式 chat。返回 Generator，每个元素形如：
     *   ['delta'=>string, 'finish_reason'=>?string, 'usage'=>?array]
     *
     * @throws LlmProviderException
     */
    public function chatStream(ChatRequest $req, string $modelName, ?float $temperature, ?int $maxTokens): \Generator
    {
        $payload = [
            'model'    => $modelName,
            'messages' => $req->messages,
            'stream'   => true,
        ];
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }
        foreach ($req->extra as $k => $v) {
            $payload[$k] = $v;
        }

        // 同步读取完整 SSE 响应体后按行解析 yield。
        // （真实边下边推需要 curl WRITEFUNCTION + Generator 联动，V1 先简化为整体读取）
        [$httpCode, $body] = $this->httpRaw('POST', '/chat/completions', $payload, [
            'Accept: text/event-stream',
        ]);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw $this->toException($httpCode, $body);
        }

        $lines = preg_split("/\n/", $body) ?: [];
        $usage = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'data:') !== 0) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]' || $data === '') {
                continue;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            $delta = $json['choices'][0]['delta']['content'] ?? '';
            if (isset($json['usage']) && is_array($json['usage'])) {
                $usage = $json['usage'];
            }
            $fr = $json['choices'][0]['finish_reason'] ?? null;
            if ($delta === '' && $fr === null) {
                continue;
            }
            yield [
                'delta'         => (string) $delta,
                'finish_reason' => $fr !== null ? (string) $fr : null,
                'usage'         => $usage,
            ];
        }
    }

    /**
     * Embedding。
     *
     * @return array{vectors:array<int,array<float>>, usage:array}
     * @throws LlmProviderException
     */
    public function embed(EmbedRequest $req, string $modelName): array
    {
        $payload = [
            'model' => $modelName,
            'input' => $req->inputList(),
        ];
        [$httpCode, $body] = $this->httpJson('POST', '/embeddings', $payload);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw $this->toException($httpCode, $body);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw new LlmProviderException("Embedding 响应格式异常：{$body}");
        }
        $vectors = [];
        foreach ($data['data'] as $item) {
            $vectors[] = array_map('floatval', $item['embedding'] ?? []);
        }
        return [
            'vectors' => $vectors,
            'usage'   => [
                'prompt_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'total_tokens'  => (int) ($data['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    // ---------- 内部工具 ----------

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /** JSON 请求（自动编码 + 解析响应体）。 */
    private function httpJson(string $method, string $path, array $payload): array
    {
        return $this->httpRaw($method, $path, $payload, [
            'Content-Type: application/json',
        ]);
    }

    /** 原始 HTTP 请求，返回 [httpCode, body]。 */
    private function httpRaw(string $method, string $path, ?array $payload, array $extraHeaders = []): array
    {
        $ch = curl_init($this->url($path));
        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
        ], $extraHeaders);

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutConnect,
            CURLOPT_TIMEOUT        => $this->timeoutRead,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new LlmProviderException("HTTP 请求失败：{$err}");
        }
        return [$httpCode, (string) $body];
    }

    private function toException(int $httpCode, string $body): LlmProviderException
    {
        $data = json_decode($body, true);
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : substr($body, 0, 500);
        return new LlmProviderException("provider HTTP {$httpCode}：{$msg}");
    }
}
