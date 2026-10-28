<?php

declare(strict_types=1);

namespace linhub\host\services\dictionary;

/** 字典越权操作（非托管方试图修改/删除，或读取私有他包数据）。 */
final class DictionaryDenied extends \RuntimeException
{
}
