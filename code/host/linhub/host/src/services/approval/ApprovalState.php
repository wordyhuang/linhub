<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

/** 状态不允许：终态单据再审批 / 再撤回、payload 超 1MB、驳回 comment 缺失等。 */
class ApprovalState extends \RuntimeException
{
}
