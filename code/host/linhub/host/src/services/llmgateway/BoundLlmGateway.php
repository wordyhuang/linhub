<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/**
 * BoundLlmGateway：把 caller 焊死在注入的实例上。
 *
 * 功能包通过 ServiceInjector 拿到 llmgateway 时，平台会用本装饰器包装，
 * 把 caller 设置为 pkg.<功能包id>。调用方再传 caller 也会被覆盖。
 *
 * 装饰器直接实现 LlmGateway 接口，对下层 LlmGatewayService 完全透明。
 */
final class BoundLlmGateway implements LlmGateway
{
    public function __construct(
        private readonly LlmGateway $inner,
        private readonly string $boundCaller,
    ) {
        if ($this->boundCaller === '') {
            throw new \InvalidArgumentException('BoundLlmGateway.boundCaller 不能为空');
        }
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->inner->chat($request->withCaller($this->boundCaller));
    }

    public function chatStream(ChatRequest $request): \Generator
    {
        return $this->inner->chatStream($request->withCaller($this->boundCaller));
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        return $this->inner->embed($request->withCaller($this->boundCaller));
    }

    public function listModels(?string $capability = null): array
    {
        return $this->inner->listModels($capability);
    }

    public function getModel(string $code): ?array
    {
        return $this->inner->getModel($code);
    }

    /** 只读暴露绑定的 caller（调试/审计用）。 */
    public function boundCaller(): string
    {
        return $this->boundCaller;
    }
}
