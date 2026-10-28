<?php

declare(strict_types=1);

namespace linhub\capability\auth;

/**
 * 当前登录身份值对象（T0.3 / C3 C1）
 *
 * 封装 UAIS 判定出的一个身份：唯一标识、名称与 tag 集合。
 * 供鉴权层判断是否超级管理员（admin_tag）等场景使用。
 */
class Identity
{
    /**
     * @param string[] $tags 该身份拥有的 tag 集合
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $tags = [],
    ) {
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}