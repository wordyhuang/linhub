<?php

declare(strict_types=1);

namespace linhub\capability\auth;

/**
 * 身份识别接口（T0.3 / C3 C1）
 *
 * 基于 UAIS 判定当前请求的身份及其 tag 集合。
 * 宿主通过该接口消费身份能力，不依赖 UAIS 具体实现。
 */
interface IdentityProvider
{
    /**
     * 解析当前身份的标识信息。
     *
     * @return Identity|null 无法解析或未认证时返回 null
     */
    public function resolve(): ?Identity;
}