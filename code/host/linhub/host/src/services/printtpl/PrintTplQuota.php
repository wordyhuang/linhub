<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

/**
 * 打印模板服务配额/边界异常：模板总数超限、正文超 1MB、票据数据超限、
 * 业务数据含资源/闭包等不可 JSON 序列化结构（PT-16）时抛出。
 */
final class PrintTplQuota extends \RuntimeException
{
}
