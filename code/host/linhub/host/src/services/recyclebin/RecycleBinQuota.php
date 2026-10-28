<?php

declare(strict_types=1);

namespace linhub\host\services\recyclebin;

/**
 * 回收站配额超限：单条 payload 超过 1MB、或单包条目数达到上限时抛出。
 */
final class RecycleBinQuota extends \RuntimeException
{
}
