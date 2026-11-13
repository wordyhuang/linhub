<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** 队列已满（本包或全平台排队数触顶，且溢出策略为 reject）。 */
class TaskQueueFull extends SchedulerException
{
}
