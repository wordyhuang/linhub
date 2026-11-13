<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * 异步任务托管服务契约（services.php 注册项 scheduler）。
 *
 * 功能包通过 `$this->app->make(TaskScheduler::class)` 或服务注入拿到实例，
 * 实例已由宿主 BoundScheduler 焊死包身份（namespace = 包短名），
 * 包内无需、也不能传入 namespace：跨包操作一律抛 TaskAccessDenied。
 *
 * 状态机：queued → running → done | failed | cancelled。
 */
interface TaskScheduler
{
    /**
     * 提交一次性任务。
     *
     * options 支持：
     *  - key        string  任务键（写入 task_run.task_key，便于检索）
     *  - delay_sec  int     延迟多少秒后执行（默认 0 = 尽快）
     *  - plan_time  string  ISO 时间，优先级低于 delay_sec
     *  - priority   int     队列优先级，越大越先执行
     *  - idem_key   string  幂等键，命中时直接返回既有 run_id
     *  - timeout_sec int   覆盖平台单任务硬上限（不得超过配置上限）
     *  - notify     bool    完成后是否站内信通知提交人（默认读配置）
     *
     * @return string run_id（32 位十六进制）
     */
    public function submit(string $handler, array $payload = [], array $options = []): string;

    /**
     * 声明（新增或覆盖）周期任务。
     *
     * options 支持：mode(cron|interval)、timezone、timeout_sec、enabled、payload。
     * interval 模式下 expr 为秒数；cron 模式下 expr 为标准五段 cron。
     *
     * @return array 定义快照（def_key / next_run / status / enabled）
     */
    public function define(string $key, string $handler, string $expr, array $options = []): array;

    /** 暂停周期任务（已生成的实例不受影响）。 */
    public function pause(string $key): bool;

    /** 恢复周期任务并重算下次触发。 */
    public function resume(string $key): bool;

    /** 立即触发一次周期任务（不影响原定 next_run）。 */
    public function triggerNow(string $key, array $payload = []): string;

    /** 下次触发时间（ISO 8601）；定义不存在或已停用返回 null。 */
    public function nextRun(string $key): ?string;

    /** 查询实例状态；跨包或不存在返回 null。 */
    public function status(string $runId): ?array;

    /** 请求取消：queued 直接取消，running 打 cancel_requested 由 handler 自行退出。 */
    public function cancel(string $runId): bool;

    /** 分页列出周期定义（本包）。 */
    public function list(array $filter = [], int $page = 1, int $size = 20): array;

    /** 分页列出执行实例（本包）。 */
    public function runs(array $filter = [], int $page = 1, int $size = 20): array;
}
