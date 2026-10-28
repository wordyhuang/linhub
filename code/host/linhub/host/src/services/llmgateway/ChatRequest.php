<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/**
 * 同步/流式对话请求 DTO。只读。
 */
final class ChatRequest
{
    /**
     * @param string $caller   调用方标识（可为空，空则由 BoundLlmGateway 注入）
     * @param string $model   模型实例 code（lg_mgt_llm_model.code）
     * @param array  $messages OpenAI 格式 [{role, content}, ...]
     * @param float|null $temperature 不传用模型默认
     * @param int|null   $max_tokens  不传用模型上限
     * @param array  $extra    透传 OpenAI 其他参数（top_p / stop 等）
     */
    public function __construct(
        public readonly string $caller,
        public readonly string $model,
        public readonly array $messages,
        public readonly ?float $temperature = null,
        public readonly ?int $max_tokens = null,
        public readonly array $extra = [],
    ) {
        if ($this->model === '') {
            throw new \InvalidArgumentException('ChatRequest.model 不能为空');
        }
        if ($this->messages === []) {
            throw new \InvalidArgumentException('ChatRequest.messages 不能为空');
        }
        foreach ($this->messages as $i => $m) {
            if (!isset($m['role'], $m['content'])) {
                throw new \InvalidArgumentException("ChatRequest.messages[{$i}] 必须含 role 与 content");
            }
        }
    }

    /** 派生一个新实例，仅覆盖 caller（供 BoundLlmGateway 注入用）。 */
    public function withCaller(string $caller): self
    {
        return new self($caller, $this->model, $this->messages, $this->temperature, $this->max_tokens, $this->extra);
    }
}
