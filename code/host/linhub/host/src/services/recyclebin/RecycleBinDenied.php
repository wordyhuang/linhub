<?php

declare(strict_types=1);

namespace linhub\host\services\recyclebin;

/**
 * 回收站服务隔离拒绝：跨包读/写/删、读 host 命名空间时抛出。
 */
final class RecycleBinDenied extends \RuntimeException
{
}
