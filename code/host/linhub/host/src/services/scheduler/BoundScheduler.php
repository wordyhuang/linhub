<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * BoundScheduler：把包身份（namespace）焊死在注入实例上。
 *
 * 功能包经 ServiceInjector / HostServiceApi 拿到 scheduler 时，宿主用本装饰器包装，
 * 每次调用自动补上 namespace = 包短名，包侧无需也不能传 namespace；
 * 跨包操作由 SchedulerService 抛 TaskAccessDenied。
 */
final class BoundScheduler implements TaskScheduler
{
    private readonly string $namespace;

    public function __construct(private readonly SchedulerService $inner, string $package)
    {
        $short = strtolower(trim($package));
        if (str_starts_with($short, 'pkg.')) {
            $short = substr($short, 4);
        }
        if ($short === '' || preg_match('/^[a-z0-9_.\-]+$/', $short) !== 1) {
            throw new TaskAccessDenied('包身份非法，无法绑定任务调度：' . $package);
        }
        $this->namespace = $short;
    }

    /** 只读暴露绑定的包名（调试/审计用）。 */
    public function boundNamespace(): string
    {
        return $this->namespace;
    }

    public function submit(string $handler, array $payload = [], array $options = []): string
    {
        return $this->inner->submit($this->namespace, $handler, $payload, $options);
    }

    public function define(string $key, string $handler, string $expr, array $options = []): array
    {
        return $this->inner->define($this->namespace, $key, $handler, $expr, $options);
    }

    public function pause(string $key): bool
    {
        return $this->inner->pause($this->namespace, $key);
    }

    public function resume(string $key): bool
    {
        return $this->inner->resume($this->namespace, $key);
    }

    public function triggerNow(string $key, array $payload = []): string
    {
        return $this->inner->triggerNow($this->namespace, $key, $payload);
    }

    public function nextRun(string $key): ?string
    {
        return $this->inner->nextRun($this->namespace, $key);
    }

    public function status(string $runId): ?array
    {
        return $this->inner->status($this->namespace, $runId);
    }

    public function cancel(string $runId): bool
    {
        return $this->inner->cancel($this->namespace, $runId);
    }

    public function list(array $filter = [], int $page = 1, int $size = 20): array
    {
        return $this->inner->list($this->namespace, $filter, $page, $size);
    }

    public function runs(array $filter = [], int $page = 1, int $size = 20): array
    {
        return $this->inner->runs($this->namespace, $filter, $page, $size);
    }
}
