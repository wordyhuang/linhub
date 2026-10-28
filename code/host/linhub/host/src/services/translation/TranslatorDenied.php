<?php

declare(strict_types=1);

namespace linhub\host\services\translation;

/**
 * 翻译服务隔离拒绝：跨包写/删、读取他包私有词条时抛出。
 */
final class TranslatorDenied extends \RuntimeException
{
}
