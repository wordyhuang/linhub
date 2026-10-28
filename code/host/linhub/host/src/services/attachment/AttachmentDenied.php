<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

/** 附件访问被拒绝（私有附件、非上传者且非管理员）。 */
final class AttachmentDenied extends \RuntimeException
{
}
