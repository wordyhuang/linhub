<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

/** 审批人解析器执行失败（类不存在 / resolve 异常 / 返回非数组）：节点标 error，单据保持 pending。 */
class ApprovalResolverError extends \RuntimeException
{
}
