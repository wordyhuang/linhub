<?php

declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use linhub\host\services\llmgateway\ChatRequest;
use linhub\host\services\llmgateway\LlmGateway;
use linhub\host\services\llmgateway\LlmModelUnavailableException;
use linhub\host\services\llmgateway\LlmProviderException;
use linhub\host\services\llmgateway\LlmQuotaExceededException;
use linhub\host\support\HostBootstrap;
use think\App;
use think\Response;

/**
 * 平台 SSE 参考代理端点（轨 B，简单场景零代码）。
 *
 * GET /index/llm/stream?model=xxx&prompt=xxx
 *
 * 设计约束（LM-8）：
 *  - 仅支持单轮 user prompt（多轮/复杂场景请走轨 A：功能包自取 Generator）
 *  - caller 从 session 推导为 user.<identity>，不允许 query 传 caller
 *  - 输出 text/event-stream，按 SSE 协议逐 chunk 推送
 *  - 异常（未登录/配额超限/模型不存在/provider 错误）以 SSE error 事件推送
 *
 * 实现说明：SSE 必须边下边推，PHP 框架默认缓冲整个响应体后再输出；
 * 因此本控制器在完成鉴权/入参/前置异常处理后直接 echo + flush，最后 exit 终止后续输出。
 */
class LlmStream extends BaseController
{
    private LlmGateway $gateway;

    public function __construct(App $app)
    {
        parent::__construct($app);
        // 平台裸调用：不走 BoundLlmGateway 自动注入，由本控制器显式传 caller
        $this->gateway = $this->app->make(LlmGateway::class);
    }

    /**
     * 单轮 SSE 对话。
     *
     * query:
     *  - model:   模型 code（必填）
     *  - prompt:  用户单轮文本（必填）
     *  - temperature: 可选，默认用模型配置
     *  - max_tokens: 可选
     */
    public function stream(): Response
    {
        // 1. 鉴权：从 session 取身份，未登录直接 error 事件返回
        $identity = (new HostBootstrap($this->app))->currentUser();
        if ($identity === null) {
            return $this->sseErrorResponse(-401, '未登录或会话已失效');
        }
        $caller = 'user.' . $identity->id;

        // 2. 入参
        $model = trim((string) $this->request->param('model', ''));
        $prompt = trim((string) $this->request->param('prompt', ''));
        $temperature = $this->request->param('temperature', null);
        $maxTokens = $this->request->param('max_tokens', null);

        if ($model === '' || $prompt === '') {
            return $this->sseErrorResponse(400, 'model 与 prompt 为必填');
        }

        // 3. 构造请求；前置异常直接走 error Response
        try {
            $req = new ChatRequest(
                caller: $caller,
                model: $model,
                messages: [['role' => 'user', 'content' => $prompt]],
                temperature: $temperature !== null ? (float) $temperature : null,
                max_tokens: $maxTokens !== null ? (int) $maxTokens : null,
            );
        } catch (LlmModelUnavailableException | LlmQuotaExceededException | LlmProviderException $e) {
            return $this->sseErrorResponse($this->exceptionCode($e), $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->sseErrorResponse(400, $e->getMessage());
        }

        // 4. 直接输出 SSE 流。此时 Response 不返回内容，控制器内部 echo 后 exit。
        $this->emitSseHeaders();
        $this->runStream($req);
        // runStream 内部 exit 终止，避免框架继续序列化 Response。
    }

    // ---------- 内部 ----------

    private function emitSseHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');
        }
        // 关闭输出缓冲（常见 php-fpm 配置）
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    private function runStream(ChatRequest $req): void
    {
        $usage = null;
        try {
            foreach ($this->gateway->chatStream($req) as $chunk) {
                $delta = $chunk['delta'] ?? '';
                if ($delta !== '') {
                    echo "data: " . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                }
                if (isset($chunk['finish_reason']) && $chunk['finish_reason'] !== null) {
                    echo "data: " . json_encode(['finish_reason' => $chunk['finish_reason']], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                }
                if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage = $chunk['usage'];
                }
            }
            echo "data: " . json_encode(['done' => true, 'usage' => $usage], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        } catch (LlmModelUnavailableException | LlmQuotaExceededException | LlmProviderException $e) {
            echo "event: error\n";
            echo "data: " . json_encode([
                'code' => $this->exceptionCode($e),
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        } catch (\Throwable $e) {
            echo "event: error\n";
            echo "data: " . json_encode([
                'code' => 500,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        exit;
    }

    private function exceptionCode(\Throwable $e): int
    {
        return match (true) {
            $e instanceof LlmQuotaExceededException => 429,
            $e instanceof LlmModelUnavailableException => 404,
            $e instanceof LlmProviderException => 502,
            default => 500,
        };
    }

    /** 前置错误：以 SSE 格式返回 JSON Response（不涉及流式，可走框架 Response）。 */
    private function sseErrorResponse(int $code, string $message): Response
    {
        $payload = json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
        $content = "event: error\ndata: {$payload}\n\n";
        return Response::create($content, 'html')
            ->header([
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
            ]);
    }
}
