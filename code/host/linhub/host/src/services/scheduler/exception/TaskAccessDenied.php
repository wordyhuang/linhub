<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** 跨包访问被拒（run_id / 定义键不属于当前包）。 */
class TaskAccessDenied extends SchedulerException
{
}
