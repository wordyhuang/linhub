<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

/**
 * 附件物理存储驱动契约（AT 存储工厂）。
 *
 * 调用方（附件服务）只面向本接口，不感知本地磁盘 / 七牛云等具体后端。
 * 物理对象 key 由附件服务统一生成（随机不可猜测），驱动只负责字节落地与读取。
 */
interface StorageInterface
{
    /** 驱动标识：local / qiniu。 */
    public function name(): string;

    /**
     * 写入对象。
     *
     * @param string $key     相对对象 key（如 202610/ab/<token>.png），驱动内部分隔符统一为 /
     * @param string $content 文件二进制内容
     * @return string 驱动内最终存储路径/对象名
     */
    public function put(string $key, string $content): string;

    /** 读取对象二进制；不存在抛 \RuntimeException。 */
    public function get(string $key): string;

    /** 对象是否存在。 */
    public function exists(string $key): bool;

    /** 删除对象；不存在视为成功。 */
    public function delete(string $key): void;

    /**
     * 对象对外访问 URL。
     *
     * @param bool $public 是否公开访问（本地驱动公开对象走静态目录，私有对象走鉴权下载控制器）
     */
    public function url(string $key, bool $public = true): string;

    /** 驱动连通性/凭证自检：返回 ['ok'=>bool, 'message'=>string]。 */
    public function ping(): array;
}
