<?php

declare(strict_types=1);

namespace linhub\host\metrics;

/**
 * 服务调用记录代理。
 *
 * 通过 __call 透明转发到真实服务实例，并在每次方法调用前登记一次统计
 * （被调用服务 / 来源 / 调用方 / 接口 / URL）。服务注入（ServiceInjector）
 * 与跨包门面（ServiceGateway）都经本代理返回，从而覆盖功能包对平台服务
 * 与包间服务的全部方法调用。
 *
 * 注意：代理对象不满足原契约接口的 instanceof 判断，功能包只按方法消费即可。
 */
final class RecordingServiceProxy
{
    public function __construct(
        private readonly object $inner,
        private readonly string $callee,
        private readonly string $source,
        private readonly ?string $callerHint = null,
    ) {
    }

    /** 包装真实服务实例；已是本代理时原样返回，避免重复计数。 */
    public static function wrap(object $inner, string $callee, string $source, ?string $callerHint = null): object
    {
        if ($inner instanceof self) {
            return $inner;
        }
        return new self($inner, $callee, $source, $callerHint);
    }

    public function __call(string $method, array $arguments): mixed
    {
        ServiceCallStatStore::record($this->callee, $this->source, $this->callerHint, $method);
        return $this->inner->{$method}(...$arguments);
    }
}
