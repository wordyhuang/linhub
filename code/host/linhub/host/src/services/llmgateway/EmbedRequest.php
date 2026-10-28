<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/** Embedding 请求 DTO。 */
final class EmbedRequest
{
    /**
     * @param string $caller 调用方标识（可空，走自动注入）
     * @param string $model  模型 code（capability=embedding）
     * @param string|array<int,string> $input 单条文本或文本数组
     */
    public function __construct(
        public readonly string $caller,
        public readonly string $model,
        public readonly string|array $input,
    ) {
        if ($this->model === '') {
            throw new \InvalidArgumentException('EmbedRequest.model 不能为空');
        }
        if ($this->input === '' || $this->input === []) {
            throw new \InvalidArgumentException('EmbedRequest.input 不能为空');
        }
    }

    public function withCaller(string $caller): self
    {
        return new self($caller, $this->model, $this->input);
    }

    /** 归一为数组（内部统一用数组处理）。 */
    public function inputList(): array
    {
        return is_array($this->input) ? $this->input : [$this->input];
    }
}
