<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/** payload 或结果超出字节上限（scheduler.quota.max_payload_bytes / max_result_bytes）。 */
class TaskPayloadTooLarge extends SchedulerException
{
}
