<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

/** 附件不存在（且非可兜底的图片类请求）。 */
final class AttachmentNotFound extends \RuntimeException
{
}
