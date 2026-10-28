<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

/** 越权：非节点审批人快照内用户审批；跨包访问他包单据 / 流程。 */
class ApprovalDenied extends \RuntimeException
{
}
