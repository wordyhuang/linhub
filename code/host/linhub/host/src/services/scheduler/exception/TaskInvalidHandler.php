<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** 处理器无法解析：未在 tasks.php 声明、类不存在或不可实例化。 */
class TaskInvalidHandler extends SchedulerException
{
}
