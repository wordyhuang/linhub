<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\attachment\AttachmentService;
use linhub\host\services\setting\SiteSettingService;

/**
 * 全局设置管理页（站点名称 / 网络备案号 / logo）—— admin 应用，仅超管
 *
 *  - GET  /admin/site_admin/index      设置页（GUI）
 *  - GET  /admin/site_admin/state      当前设置 JSON（含 logo 预览地址）
 *  - POST /admin/site_admin/save       保存站点名称与备案号
 *  - POST /admin/site_admin/appearanceSave 保存外观与引导（换肤开关 / 默认皮肤 / 系统指导按钮）
 *  - POST /admin/site_admin/logoUpload 上传/更换站点 logo（multipart 字段 file）
 *  - POST /admin/site_admin/logoReset  清除 logo（回退内置图）
 *
 * 设置存 data/platform/setting.json（SiteSettingService，原子写、字段白名单）。
 * logo 走公开附件链路（is_public=true），登录页等未登录入口可直接经 /attachment/download 预览。
 */
class SiteAdmin extends AdminController
{
    private function service(): SiteSettingService
    {
        return new SiteSettingService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('site_admin/index');
    }

    /** JSON：当前全局设置（含 logo 预览地址与备案链接）。 */
    public function state()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $svc = $this->service();
        return $this->jsonOk([
            'site_name'  => $svc->get('site_name', SiteSettingService::DEFAULT_SITE_NAME),
            'icp'        => $svc->icp(),
            'logo_token' => $svc->get('logo_token', ''),
            'logo_url'   => $svc->logoUrl(),
            'icp_link'   => SiteSettingService::ICP_LINK,
            'allow_skin'    => $svc->skinChangeAllowed(),
            'default_skin'  => $svc->defaultSkin(),
            // 平台提供的皮肤 key 白名单（前端默认皮肤下拉按此过滤）
            'allowed_skins' => SiteSettingService::ALLOWED_SKINS,
            'guide_enabled' => $svc->guideEnabled(),
        ]);
    }

    /** 保存外观与引导设置（换肤开关 / 平台默认皮肤 / 系统指导按钮）。 */
    public function appearanceSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $skin = trim((string) $this->request->post('default_skin', ''));
        if ($skin !== '' && !preg_match('/^[\w-]{1,32}$/', $skin)) {
            return $this->jsonFail('默认皮肤标识不合法');
        }
        // 平台只提供白名单内皮肤（清单外直接拒绝，防止绕过前端下拉写入遗留 key）
        if ($skin !== '' && !SiteSettingService::isSkinAllowed($skin)) {
            return $this->jsonFail('平台未提供该皮肤');
        }
        try {
            $this->service()->save([
                'allow_skin'    => $this->request->post('allow_skin') === '0' ? '0' : '1',
                'default_skin'  => $skin,
                'guide_enabled' => $this->request->post('guide_enabled') === '0' ? '0' : '1',
            ]);
            $this->audit($gate, 'site.setting.appearance.save', 'skin=' . ($skin !== '' ? $skin : 'default'));
            return $this->jsonOk(null, '外观与引导设置已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 保存站点名称与备案号。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $saved = $this->service()->save([
                'site_name' => $this->request->post('site_name', ''),
                'icp'       => $this->request->post('icp', ''),
            ]);
            $this->audit($gate, 'site.setting.save', 'site_name=' . $saved['site_name']);
            return $this->jsonOk(null, '全局设置已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 上传/更换站点 logo（multipart 字段 file，公开附件）。 */
    public function logoUpload()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $file = $this->request->file('file');
        if ($file === null) {
            return $this->jsonFail('未收到上传文件（字段名 file）');
        }
        try {
            $content = (string) file_get_contents($file->getPathname());
            $origin  = (string) $file->getOriginalName();
            $meta = (new AttachmentService())->store(
                AttachmentService::SETTING_NS,
                $content,
                $origin,
                ['is_public' => true, 'uploader_id' => $gate->id, 'uploader_name' => '平台设置'],
            );
            $token = (string) ($meta['token'] ?? '');
            if ($token === '') {
                return $this->jsonFail('logo 上传失败：未返回附件 token');
            }
            $this->service()->save(['logo_token' => $token]);
            $this->audit($gate, 'site.setting.logo.upload', $token);
            return $this->jsonOk(['token' => $token], '站点 logo 已更新');
        } catch (\Throwable $e) {
            return $this->jsonFail('logo 上传失败：' . $e->getMessage());
        }
    }

    /** 清除站点 logo（回退内置图）。 */
    public function logoReset()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $this->service()->save(['logo_token' => '']);
            $this->audit($gate, 'site.setting.logo.reset', '');
            return $this->jsonOk(null, '已清除站点 logo，恢复内置图');
        } catch (\Throwable $e) {
            return $this->jsonFail('清除失败：' . $e->getMessage());
        }
    }

    /** 超管操作留痕（管控轨迹）。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
            // 留痕失败不阻断主流程
        }
    }
}
