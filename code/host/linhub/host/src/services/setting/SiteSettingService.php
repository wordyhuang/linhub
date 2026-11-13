<?php

declare(strict_types=1);

namespace linhub\host\services\setting;

use linhub\host\rbac\JsonFileStore;
use linhub\host\support\HostBootstrap;

/**
 * 全局设置服务（平台站点配置：站点名称 / 网络备案号 / logo）
 *
 *  - 存储：data/platform/setting.json（组合 JsonFileStore 原子写，零迁移）；
 *  - 字段白名单：site_name / icp / logo_token / allow_skin / default_skin / guide_enabled，
 *    save() 时 trim 清洗、未知键丢弃、站点名非空校验；
 *  - 游客安全出口 publicProfile()：仅暴露 name/icp/logoUrl，绝不外泄内部配置；
 *  - logo 走公开附件链路（token 经 /attachment/download 展示），登录页等未登录入口可直接预览；
 *  - 备案号按规定链接到工信部 ICP/IPv6 域名信息备案管理系统。
 */
final class SiteSettingService
{
    /** 未配置时的默认站点名称（与历史硬编码品牌一致）。 */
    public const DEFAULT_SITE_NAME = 'LinHub 林枢';

    /** 备案号链接：工信部 ICP/IPv6 域名信息备案管理系统（新窗口）。 */
    public const ICP_LINK = 'https://beian.miit.gov.cn/';

    /** 工作台默认皮肤 key（workdesktop-ai 预设，未配置时）。 */
    public const DEFAULT_SKIN = 'default';

    /**
     * 平台对外提供的皮肤 key 白名单（workdesktop-ai 内置预设的收窄子集）。
     * 对应风格：默认 / 时尚 / 扁平 / 酷炫 / 党政风 / 苹果 / Soft UI / 宋韵。
     * 清单外皮肤（商务/科技/赛博朋克/中式/字节/蚂蚁等）一律不提供：
     * 前端选择清单按此过滤，skinSave/appearanceSave 按此拒绝，读取时清单外值回退默认。
     */
    public const ALLOWED_SKINS = ['default', 'fashion', 'flat', 'cool', 'governance', 'apple', 'soft', 'song'];

    private JsonFileStore $store;

    /** 注入文件路径（验证可指向 temp/）；空则走 HostBootstrap 默认 data 路径。 */
    public function __construct(?string $file = null)
    {
        if ($file === null || $file === '') {
            $file = (new HostBootstrap())->settingFile();
        }
        $this->store = new JsonFileStore($file);
    }

    /**
     * 全部设置（未配置字段取默认值，值一律 trim）。
     *
     * @return array{site_name:string, icp:string, logo_token:string, allow_skin:string, default_skin:string, guide_enabled:string}
     */
    public function all(): array
    {
        $data = $this->store->read();
        return [
            'site_name'     => trim((string) ($data['site_name'] ?? self::DEFAULT_SITE_NAME)),
            'icp'           => trim((string) ($data['icp'] ?? '')),
            'logo_token'    => trim((string) ($data['logo_token'] ?? '')),
            'allow_skin'    => trim((string) ($data['allow_skin'] ?? '1')),
            'default_skin'  => trim((string) ($data['default_skin'] ?? self::DEFAULT_SKIN)) ?: self::DEFAULT_SKIN,
            'guide_enabled' => trim((string) ($data['guide_enabled'] ?? '1')),
        ];
    }

    /** 读取单键设置；不存在返回 $default。 */
    public function get(string $key, mixed $default = ''): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * 保存设置：仅收白名单字段、trim 清洗、站点名非空校验；未知键丢弃不落盘。
     * 返回清洗后的全量设置。
     *
     * @throws \InvalidArgumentException 站点名称为空时
     */
    public function save(array $data): array
    {
        $clean = $this->all();
        foreach (['site_name', 'icp', 'logo_token', 'allow_skin', 'default_skin', 'guide_enabled'] as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = trim((string) $data[$key]);
            }
        }
        if ($clean['site_name'] === '') {
            throw new \InvalidArgumentException('站点名称不能为空');
        }
        $this->store->write($clean);
        return $clean;
    }

    /** 网络备案号（已清洗；未配置返回空串）。 */
    public function icp(): string
    {
        return (string) $this->get('icp', '');
    }

    /** 是否允许用户自行更换工作台皮肤（默认允许；关闭时统一用平台默认皮肤）。 */
    public function skinChangeAllowed(): bool
    {
        return $this->get('allow_skin', '1') !== '0';
    }

    /** 皮肤 key 是否在平台提供的白名单内。 */
    public static function isSkinAllowed(string $key): bool
    {
        return in_array($key, self::ALLOWED_SKINS, true);
    }

    /** 平台默认皮肤 key（用户未自设皮肤或换肤被关闭时生效；清单外存量值回退默认）。 */
    public function defaultSkin(): string
    {
        $skin = (string) $this->get('default_skin', self::DEFAULT_SKIN);
        return self::isSkinAllowed($skin) ? $skin : self::DEFAULT_SKIN;
    }

    /** 是否显示工作台右上角「系统指导」按钮（默认显示）。 */
    public function guideEnabled(): bool
    {
        return $this->get('guide_enabled', '1') !== '0';
    }

    /** logo 展示地址：有 token 拼接公开下载地址，否则空串（调用方回退内置图）。 */
    public function logoUrl(): string
    {
        $token = (string) $this->get('logo_token', '');
        return $token !== '' ? '/attachment/download?token=' . rawurlencode($token) : '';
    }

    /**
     * 游客/公开页面可见的安全子集（登录页等未登录入口消费）。
     * 绝不暴露 logo_token 等内部字段。
     *
     * @return array{name:string, icp:string, logoUrl:string, icpLink:string}
     */
    public function publicProfile(): array
    {
        return [
            'name'    => (string) $this->get('site_name', self::DEFAULT_SITE_NAME),
            'icp'     => $this->icp(),
            'logoUrl' => $this->logoUrl(),
            'icpLink' => self::ICP_LINK,
        ];
    }
}
