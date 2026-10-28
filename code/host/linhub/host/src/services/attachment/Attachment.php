<?php

declare(strict_types=1);

namespace linhub\host\services\attachment;

use linhub\capability\auth\Identity;

/**
 * 附件托管服务契约（AT-1 ~ AT-12）。
 *
 * 调用方（功能包/管理端）只面对 Token 与本接口，不感知物理存储：
 *  - store()  上传托管，返回不可猜测的随机短 Token 与统一访问 URL；
 *  - fetch()  凭 Token 提取，服务按 is_public + 登录身份裁决；图片缺失走默认图兜底；
 *  - delete() 软删除元数据并清除物理对象；
 *  - 其余方法供管理端 GUI（文件管理器/存储状态/默认图/清理记录）使用。
 */
interface Attachment
{
    /**
     * 托管一段附件内容（AT-1/2/4/7）。
     *
     * @param string $ownerPackage 归属功能包短名
     * @param string $content      文件二进制内容
     * @param string $originName   原始文件名（用于推断扩展名与展示）
     * @param array  $meta         可选键：is_public(bool)、uploader_id、uploader_name、
     *                             expire_days(int，0=永久)、mime(string)
     * @return array 附件元数据（含 token、url）
     */
    public function store(string $ownerPackage, string $content, string $originName, array $meta = []): array;

    /**
     * 凭 Token 提取附件（AT-3/8）。
     *
     * @param string         $token  附件短 Token
     * @param ?Identity $viewer 当前访问者（公开附件可传 null）
     * @return array {content, mime, name, attachment, fallback}
     * @throws AttachmentDenied  非公开且访问者无权
     * @throws AttachmentNotFound 非图片附件不存在
     */
    public function fetch(string $token, ?Identity $viewer = null): array;

    /** 删除附件：管理员或上传者本人（AT-5）。 */
    public function delete(string $token, ?Identity $viewer = null, bool $asAdmin = false): void;

    /** 按 Token 取附件元数据（管理器/预览用），不存在返回 null。 */
    public function findByToken(string $token): ?array;

    /** 生成统一访问 URL（公开/私有同入口，权限在 fetch 裁决）。 */
    public function url(string $token): string;

    /** 附件文件管理器分页查询（AT-10）。 */
    public function paginate(array $filter = [], int $page = 1, int $size = 20): array;

    /** 附件用量统计（AT-10/PS-1）。 */
    public function stats(): array;

    /** 当前生效的存储/托管配置（密钥仅显示是否已配置，AT-11）。 */
    public function configState(): array;

    /** 驱动连通性测试（AT-11）。 */
    public function testDriver(string $driver = ''): array;

    /** 设置默认图片（AT-9），返回默认图附件信息。 */
    public function setFallbackImage(string $content, string $originName, string $operatorId = ''): array;

    /** 还原为系统内置默认图（AT-9）。 */
    public function resetFallbackImage(): void;

    /** 取当前默认图片内容（AT-8）：['content','mime','configured'=>bool]。 */
    public function fallbackImage(): array;

    /** 当前已托管默认图 Token（AT-9 预览用），未配置返回空串。 */
    public function fallbackToken(): string;

    /** 保存可托管参数（默认公开性、默认有效期、缺图兜底开关）。 */
    public function saveSettings(array $settings): void;

    /** 执行一次过期/孤儿清理（AT-6/12）。 */
    public function clean(string $triggerType = 'manual', string $operatorId = ''): array;

    /** 清理记录分页（AT-12）。 */
    public function cleanRecords(int $page = 1, int $size = 20): array;
}
