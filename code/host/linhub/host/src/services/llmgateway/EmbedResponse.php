<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/** Embedding 响应 DTO。 */
final class EmbedResponse
{
    /**
     * @param array<int,array<float>> $vectors 与 input 一一对应的向量
     * @param array $usage {prompt_tokens, total_tokens}
     * @param string $model
     * @param string $call_id
     */
    public function __construct(
        public readonly array $vectors,
        public readonly array $usage,
        public readonly string $model,
        public readonly string $call_id,
    ) {
    }

    public function toArray(): array
    {
        return [
            'vectors' => $this->vectors,
            'usage'   => $this->usage,
            'model'   => $this->model,
            'call_id' => $this->call_id,
        ];
    }
}
