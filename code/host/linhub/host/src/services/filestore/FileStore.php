<?php

declare(strict_types=1);

namespace linhub\host\services\filestore;

/**
 * 文件存储契约（T1.7 / M8）
 *
 * 面向功能包的文件读写能力；物理存储（本地/OSS）由宿主实现。路径以包短名隔离。
 */
interface FileStore
{
    /** 写入文件，返回可访问的相对路径。 */
    public function put(string $path, string $content): string;

    /** 读取文件内容；不存在抛 \InvalidArgumentException。 */
    public function get(string $path): string;

    /** 判断文件是否存在。 */
    public function exists(string $path): bool;

    /** 删除文件。 */
    public function delete(string $path): void;
}