<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler\exception;

/**
 * 异步任务托管异常基类：所有对外抛出的业务异常都继承它，
 * 便于功能包统一捕获并区分「调度器拒绝」与「普通运行时异常」。
 */
class SchedulerException extends \RuntimeException
{
}
