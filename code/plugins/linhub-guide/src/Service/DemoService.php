<?php

declare(strict_types=1);

namespace Lg\Pack\LinhubGuide\Service;

/**
 * 示例服务：注册为宿主服务供其他包/宿主消费（N3/M）。
 */
class DemoService
{
    public function greet(string $name): string
    {
        return "问候，{$name}";
    }
}