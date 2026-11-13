<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

/**
 * 任务处理器契约：功能包在包根 tasks.php 中把「处理器标识」映射到实现类。
 *
 * 约束：
 *  - 必须是可实例化的类（安装期不检查 class_exists，运行期解析失败判 failed(invalid_handler)）；
 *  - handle() 返回数组，作为 task_run.result 持久化（受 max_result_bytes 限制）；
 *  - 长任务必须周期性调用 $context->heartbeat()，返回 false 表示已被取消或租约丢失，应立即退出。
 */
interface TaskHandler
{
    /**
     * @return array 执行结果（可为空数组）
     */
    public function handle(TaskContext $context): array;
}
