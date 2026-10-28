<?php

declare(strict_types=1);

namespace linhub\host\services\llmgateway;

/** provider 调用失败（HTTP 错误/超时/协议错误）。 */
class LlmProviderException extends \RuntimeException
{
}
