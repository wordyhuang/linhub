<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** 包侧配额触顶（周期定义数、保留数等）。 */
class TaskQuotaExceeded extends SchedulerException
{
}
