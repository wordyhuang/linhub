<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/** 同步对话响应 DTO。 */
final class ChatResponse
{
    /**
     * @param string $content        模型输出文本
     * @param string $finish_reason   stop / length / ...
     * @param array  $usage           {prompt_tokens, completion_tokens, total_tokens}
     * @param string $model           实际调用的模型 code
     * @param string $call_id        审计 ID
     */
    public function __construct(
        public readonly string $content,
        public readonly string $finish_reason,
        public readonly array $usage,
        public readonly string $model,
        public readonly string $call_id,
    ) {
    }

    /** 便捷转数组（供 JSON 响应）。 */
    public function toArray(): array
    {
        return [
            'content'       => $this->content,
            'finish_reason'  => $this->finish_reason,
            'usage'         => $this->usage,
            'model'         => $this->model,
            'call_id'       => $this->call_id,
        ];
    }
}
