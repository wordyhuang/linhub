<?php

declare(strict_types=1);

namespace linhub\capability\auth;

/**
 * UAIS 服务异常：网络不通、响应无法解析、获取应用凭证失败等基础设施层错误。
 *
 * 业务级错误（token 失效、无权限、参数错误等）不抛本异常，
 * 以响应 envelope（code/message/data）返回，由调用方判定。
 */
class UaisException extends \RuntimeException
{
}
