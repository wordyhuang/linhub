<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway\Support;

use linhub\host\services\support\PlatformStore;

/**
 * LLM 调用日志写入器。
 *
 * 落 lg_mgt_llm_call_log 表，一次调用一条记录。双 caller 字段：
 *  - caller_effective：实际生效的 caller（自动注入或显式传入）
 *  - caller_declared ：调用方在请求中声明的 caller（可能为空）
 *
 * 状态枚举：success / failed / aborted / quota_exceeded
 */
final class LlmCallLogger
{
    public const STATUS_SUCCESS        = 'success';
    public const STATUS_FAILED         = 'failed';
    public const STATUS_ABORTED        = 'aborted';
    public const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    public const TYPE_CHAT   = 'chat';
    public const TYPE_STREAM = 'stream';
    public const TYPE_EMBED  = 'embed';

    public function __construct(
        private readonly PlatformStore $store,
    ) {
    }

    /**
     * 创建一条新的调用上下文（在调用 provider 之前调用），返回 call_id。
     * 之后通过 update() 回填最终结果。
     */
    public function begin(string $callerEffective, ?string $callerDeclared, string $modelCode, string $provider, string $type): string
    {
        $callId = bin2hex(random_bytes(16));
        $now    = date('c');

        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' (call_id, caller_effective, caller_declared, model_code, provider, type, status, usage_estimated, created_at) VALUES ('
            . $this->store->quote($callId) . ', '
            . $this->store->quote($callerEffective) . ', '
            . ($callerDeclared !== null ? $this->store->quote($callerDeclared) : 'NULL') . ', '
            . $this->store->quote($modelCode) . ', '
            . $this->store->quote($provider) . ', '
            . $this->store->quote($type) . ', '
            . $this->store->quote(self::STATUS_FAILED) . ', '
            . '0, '
            . $this->store->quote($now) . ')'
        );
        return $callId;
    }

    /**
     * 回填最终结果。
     *
     * @param array $result
     */
    public function update(string $callId, array $result): void
    {
        $fields = [];
        $fields[] = 'status = ' . $this->store->quote($result['status'] ?? self::STATUS_FAILED);

        if (array_key_exists('prompt_text', $result)) {
            $v = $result['prompt_text'];
            $fields[] = 'prompt_text = ' . ($v !== null ? $this->store->quote(mb_substr((string) $v, 0, 16000)) : 'NULL');
        }
        if (array_key_exists('completion_text', $result)) {
            $v = $result['completion_text'];
            $fields[] = 'completion_text = ' . ($v !== null ? $this->store->quote(mb_substr((string) $v, 0, 16000)) : 'NULL');
        }
        if (array_key_exists('prompt_tokens', $result)) {
            $v = $result['prompt_tokens'];
            $fields[] = 'prompt_tokens = ' . ($v !== null ? (int) $v : 'NULL');
        }
        if (array_key_exists('completion_tokens', $result)) {
            $v = $result['completion_tokens'];
            $fields[] = 'completion_tokens = ' . ($v !== null ? (int) $v : 'NULL');
        }
        if (array_key_exists('usage_estimated', $result)) {
            $v = $result['usage_estimated'];
            $fields[] = 'usage_estimated = ' . ($v !== null ? (int) $v : '0');
        }
        if (array_key_exists('duration_ms', $result)) {
            $v = $result['duration_ms'];
            $fields[] = 'duration_ms = ' . ($v !== null ? (int) $v : 'NULL');
        }
        if (array_key_exists('cost_input', $result)) {
            $v = $result['cost_input'];
            $fields[] = 'cost_input = ' . ($v !== null ? (float) $v : 'NULL');
        }
        if (array_key_exists('cost_output', $result)) {
            $v = $result['cost_output'];
            $fields[] = 'cost_output = ' . ($v !== null ? (float) $v : 'NULL');
        }
        if (array_key_exists('error_summary', $result)) {
            $v = $result['error_summary'];
            $fields[] = 'error_summary = ' . ($v !== null ? $this->store->quote(mb_substr((string) $v, 0, 500)) : 'NULL');
        }

        $this->store->db()->exec(
            'UPDATE ' . PlatformStore::LLM_CALL_LOG_TABLE
            . ' SET ' . implode(', ', $fields)
            . ' WHERE call_id = ' . $this->store->quote($callId)
        );
    }
}
