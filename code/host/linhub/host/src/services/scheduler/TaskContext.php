<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * 处理器运行上下文：包身份 + 载荷 + 进度/取消/续期回调。
 *
 * 由调度器在认领实例后构造，仅在一次 handle() 生命周期内有效。
 */
final class TaskContext
{
    /** @var callable(int, string): void */
    private $reporter;

    /** @var callable(): bool */
    private $cancelChecker;

    public function __construct(
        private string $runId,
        private string $package,
        private string $taskKey,
        private array $payload,
        private int $timeoutSec,
        callable $reporter,
        callable $cancelChecker
    ) {
        $this->reporter = $reporter;
        $this->cancelChecker = $cancelChecker;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function taskKey(): string
    {
        return $this->taskKey;
    }

    /** 提交时传入的载荷（已 JSON 解码）。 */
    public function payload(): array
    {
        return $this->payload;
    }

    /** 单任务硬上限（秒），0 表示不限制。 */
    public function timeoutSec(): int
    {
        return $this->timeoutSec;
    }

    /** 上报进度（0~100）与消息；写库失败不抛异常。 */
    public function progress(int $percent, string $message = ''): void
    {
        ($this->reporter)(max(0, min(100, $percent)), $message);
    }

    /**
     * 续期租约并检查取消请求。
     *
     * @return bool false = 已被取消或租约已丢失，handler 应尽快返回
     */
    public function heartbeat(): bool
    {
        ($this->reporter)(-1, '');
        return !($this->cancelChecker)();
    }

    public function cancelled(): bool
    {
        return ($this->cancelChecker)();
    }

    /** 分段睡眠（每秒检查一次取消），被取消时提前返回 false。 */
    public function sleep(int $seconds): bool
    {
        $until = microtime(true) + max(0, $seconds);
        while (microtime(true) < $until) {
            if (!$this->heartbeat()) {
                return false;
            }
            usleep(500000);
        }
        return true;
    }
}
