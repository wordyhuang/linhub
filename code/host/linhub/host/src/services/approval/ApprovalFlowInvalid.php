<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

/** 流程定义校验失败（环 / 缺 entry / 网关不完整 / approvers 为空等），错误信息指明节点 id 与原因。 */
class ApprovalFlowInvalid extends \RuntimeException
{
}
