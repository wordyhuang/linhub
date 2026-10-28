<?php

declare(strict_types=1);

namespace linhub\host\services\id;

/**
 * ID 生成器契约（T1.7 / M8）
 *
 * 提供全局唯一 ID（雪花/有序 ID），供功能包生成业务主键。保证进程内与跨进程唯一性。
 */
interface IdGenerator
{
    /** 生成一个全局唯一 ID。 */
    public function generate(): string;

    /** 生成一个长整型数字 ID（适用于数值主键）。 */
    public function generateInt(): int;
}