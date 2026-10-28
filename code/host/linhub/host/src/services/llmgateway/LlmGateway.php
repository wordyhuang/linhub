<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/**
 * LLM 网关服务契约。
 *
 * 功能包与平台内部服务通过本接口调用大模型能力，不感知底层 provider。
 * caller 解析规则（V1.1）：
 *  - 请求中显式传 caller → 直接使用
 *  - 经 ServiceInjector 拿到的实例已由 BoundLlmGateway 装饰器焊死 pkg.<id>
 *  - 两者都为空（平台裸调用） → 抛 InvalidArgumentException
 */
interface LlmGateway
{
    /**
     * 同步对话。
     *
     * @throws \InvalidArgumentException    caller/messages 非法
     * @throws LlmModelUnavailableException model 不存在或未启用
     * @throws LlmQuotaExceededException    配额超限
     * @throws LlmProviderException         provider 调用失败
     */
    public function chat(ChatRequest $request): ChatResponse;

    /**
     * 流式对话，逐 chunk 产出。
     *
     * @return \Generator 每个元素为 ['delta'=>string, 'finish_reason'=>?string, 'usage'=>?array]
     */
    public function chatStream(ChatRequest $request): \Generator;

    /**
     * 向量嵌入。
     *
     * @throws \InvalidArgumentException    model 非 embedding 能力
     * @throws LlmModelUnavailableException
     * @throws LlmQuotaExceededException
     * @throws LlmProviderException
     */
    public function embed(EmbedRequest $request): EmbedResponse;

    /**
     * 列出可用模型实例（供功能包下拉选择）。
     *
     * @param string|null $capability 过滤能力：chat / chat_stream / embedding；null=全部
     * @return array<int, array{code:string,provider:string,model_name:string,capability:string,max_tokens:int}>
     */
    public function listModels(?string $capability = null): array;

    /** 查单个模型详情，不存在返回 null。 */
    public function getModel(string $code): ?array;
}
