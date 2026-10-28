<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway\Support;

use linhub\host\services\llmgateway\LlmQuotaExceededException;
use linhub\host\services\support\PlatformStore;

/**
 * 配额守卫。
 *
 * 双维度配额：
 *  1. 模型级：lg_mgt_llm_model.daily_token_quota（该模型每天总 token 上限）
 *  2. caller 级：lg_mgt_llm_caller_quota.daily_token_limit（该 caller 对某模型的日 token 上限）
 *
 * V1 简化：只做"是否超过"的硬闸门，不做并发锁、不做预扣。
 * 调用 provider 之后由 LlmCallLogger.update() 回填 usage，下次调用时统计今日已用。
 */
final class LlmQuotaGuard
{
    public function __construct(
        private readonly PlatformStore $store,
    ) {
    }

    /**
     * 调用前校验今日配额余量。
     *
     * @param array $modelRow lg_mgt_llm_model 一行，含 code/daily_token_quota
     * @throws LlmQuotaExceededException
     */
    public function assertAvailable(string $callerEffective, array $modelRow): void
    {
        $modelCode = (string) ($modelRow['code'] ?? '');

        // 模型级配额
        $modelQuota = (int) ($modelRow['daily_token_quota'] ?? 0);
        if ($modelQuota > 0) {
            $used = $this->todayUsedByModel($modelCode);
            if ($used >= $modelQuota) {
                throw new LlmQuotaExceededException(
                    "模型 {$modelCode} 今日 token 已用尽：{$used}/{$modelQuota}"
                );
            }
        }

        // caller 级配额
        $callerQuota = $this->todayLimitForCaller($callerEffective, $modelCode);
        if ($callerQuota > 0) {
            $used = $this->todayUsedByCallerModel($callerEffective, $modelCode);
            if ($used >= $callerQuota) {
                throw new LlmQuotaExceededException(
                    "caller {$callerEffective} 在模型 {$modelCode} 上今日 token 已用尽：{$used}/{$callerQuota}"
                );
            }
        }
    }

    /** 今日某模型已用 token 总数（prompt+completion 求和）。 */
    public function todayUsedByModel(string $modelCode): int
    {
        $rows = $this->store->db()->query(
            'SELECT COALESCE(SUM(prompt_tokens),0) AS p, COALESCE(SUM(completion_tokens),0) AS c FROM '
            . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE model_code = ' . $this->store->quote($modelCode)
            . ' AND created_at >= ' . $this->store->quote($this->todayStart())
        );
        if (!$rows) {
            return 0;
        }
        return (int) ($rows[0]['p'] ?? 0) + (int) ($rows[0]['c'] ?? 0);
    }

    /** 今日某 caller 在某模型上已用 token。 */
    public function todayUsedByCallerModel(string $caller, string $modelCode): int
    {
        $rows = $this->store->db()->query(
            'SELECT COALESCE(SUM(prompt_tokens),0) AS p, COALESCE(SUM(completion_tokens),0) AS c FROM '
            . PlatformStore::LLM_CALL_LOG_TABLE
            . ' WHERE caller_effective = ' . $this->store->quote($caller)
            . ' AND model_code = ' . $this->store->quote($modelCode)
            . ' AND created_at >= ' . $this->store->quote($this->todayStart())
        );
        if (!$rows) {
            return 0;
        }
        return (int) ($rows[0]['p'] ?? 0) + (int) ($rows[0]['c'] ?? 0);
    }

    /** 读取 lg_mgt_llm_caller_quota 某 caller+model 的日配额（0 表示不限）。 */
    public function todayLimitForCaller(string $caller, string $modelCode): int
    {
        $rows = $this->store->db()->query(
            'SELECT daily_token_limit FROM ' . PlatformStore::LLM_CALLER_QUOTA_TABLE
            . ' WHERE caller = ' . $this->store->quote($caller)
            . ' AND model_code = ' . $this->store->quote($modelCode)
            . ' LIMIT 1'
        );
        if (!$rows) {
            return 0;
        }
        return (int) ($rows[0]['daily_token_limit'] ?? 0);
    }

    // ---------- 内部 ----------

    private function todayStart(): string
    {
        return (new \DateTimeImmutable('today 00:00:00'))->format('c');
    }
}
