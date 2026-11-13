<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

/**
 * 打印模板服务业务拒绝：编号不存在 / 模板已停用 / 他包私有模板（无权使用）时抛出。
 * 对应 AC-3：均以业务异常表达，功能包侧捕获后转业务提示，而非 500。
 */
final class PrintTplDenied extends \RuntimeException
{
}
