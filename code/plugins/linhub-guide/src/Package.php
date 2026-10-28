<?php

declare(strict_types=1);

namespace Lg\Pack\LinhubGuide;

use think\Service;

/**
 * 功能包入口类：可选，提供 install/upgrade/rollback 生命周期钩子。
 */
class Package extends Service
{
    public function install(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function rollback(): void
    {
    }
}