<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** 周期表达式非法（cron 段数/取值越界，或 interval 非正整数）。 */
class TaskInvalidSchedule extends SchedulerException
{
}
