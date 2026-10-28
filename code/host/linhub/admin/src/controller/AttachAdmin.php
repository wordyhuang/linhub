<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\attachment\AttachmentDenied;
use linhub\host\services\attachment\AttachmentService;

/**
 * 附件托管服务管理端（AT-9 ~ AT-12）—— admin 应用，仅超管
 *
 *  - GET  /admin/attach_admin/fallback       AT-9 默认图片设置页
 *  - POST /admin/attach_admin/fallbackUpload 上传/更换默认图片
 *  - POST /admin/attach_admin/fallbackReset  还原内置默认图
 *  - POST /admin/attach_admin/settingsSave   托管参数保存（默认公开性/默认有效期/兜底开关）
 *  - GET  /admin/attach_admin/files          AT-10 附件文件管理器页
 *  - GET  /admin/attach_admin/rows           附件分页 JSON（状态/归属/公开性/关键字筛选）
 *  - POST /admin/attach_admin/upload         管理员上传附件（multipart：file/owner_pkg/is_public/expire_days）
 *  - POST /admin/attach_admin/delete         管理员删除附件
 *  - GET  /admin/attach_admin/storage        AT-11 存储配置与驱动状态页
 *  - GET  /admin/attach_admin/config         当前生效配置 JSON（密钥只回显是否已配置）
 *  - POST /admin/attach_admin/driverTest     存储驱动连通性测试
 *  - GET  /admin/attach_admin/clean          AT-12 清理记录页
 *  - GET  /admin/attach_admin/cleanRows      清理记录分页 JSON
 *  - POST /admin/attach_admin/cleanRun       手动触发一次清理
 *
 * 密钥安全：config 仅回显七牛 AK/SK 是否非空，绝不回显原文。
 */
class AttachAdmin extends AdminController
{
    private function service(): AttachmentService
    {
        return new AttachmentService();
    }

    // ===== AT-9 默认图片设置 =====

    public function fallback()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('attach_admin/fallback');
    }

    /** JSON：默认图与托管参数当前状态。 */
    public function fallbackState()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $svc = $this->service();
        $fb = $svc->fallbackImage();
        $cfg = $svc->configState();
        return $this->jsonOk([
            'fallback_configured' => (bool) ($fb['configured'] ?? false),
            'fallback_mime'       => (string) ($fb['mime'] ?? ''),
            'preview_token'       => $this->fallbackToken(),
            'default_public'      => (bool) $cfg['default_public'],
            'default_expire_days' => (int) $cfg['default_expire_days'],
            'fallback_enabled'    => (bool) $cfg['fallback_enabled'],
        ]);
    }

    /** 上传/更换默认图片（multipart 字段 file）。 */
    public function fallbackUpload()
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
            $meta = $this->service()->setFallbackImage($content, $origin, $gate->id);
            $this->audit($gate, 'attach.fallback.upload', (string) ($meta['token'] ?? ''));
            return $this->jsonOk(['token' => $meta['token'] ?? ''], '默认图片已更新');
        } catch (\Throwable $e) {
            return $this->jsonFail('默认图片上传失败：' . $e->getMessage());
        }
    }

    /** 还原内置默认图（清除已托管 Token 与兜底设置引用）。 */
    public function fallbackReset()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $this->service()->resetFallbackImage();
        $this->audit($gate, 'attach.fallback.reset', '');
        return $this->jsonOk(null, '已还原为内置默认图');
    }

    /** 托管参数保存。 */
    public function settingsSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $this->service()->saveSettings([
                'default_public'      => $this->request->post('default_public', '0'),
                'default_expire_days' => $this->request->post('default_expire_days', '0'),
                'fallback_enabled'    => $this->request->post('fallback_enabled', '1'),
            ]);
            $this->audit($gate, 'attach.settings.save', '');
            return $this->jsonOk(null, '托管参数已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    // ===== AT-10 附件文件管理器 =====

    public function files()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('attach_admin/files');
    }

    /** JSON：附件分页（data-grid 数据源）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['status', 'owner_pkg', 'uploader_id', 'is_public', 'keyword'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        $result = $this->service()->paginate($filter, $page, $size);
        $items = [];
        foreach ((array) ($result['items'] ?? []) as $row) {
            $row['url'] = $this->service()->url((string) $row['token']);
            $items[] = $row;
        }
        return $this->jsonOk([
            'list'        => $items,
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $this->service()->stats(),
        ]);
    }

    /**
     * 管理员上传附件（multipart 字段 file）。
     *
     * 可附带 owner_pkg（默认 admin）、is_public（默认 0）、expire_days（默认 0 永不过期）。
     * 大小/类型白名单由 AttachmentService 统一按 env 约束校验。
     */
    public function upload()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $file = $this->request->file('file');
        if ($file === null) {
            return $this->jsonFail('未收到上传文件（字段名 file）');
        }
        $ownerPkg = trim((string) $this->request->post('owner_pkg', 'admin'));
        if ($ownerPkg === '') {
            $ownerPkg = 'admin';
        }
        if (!preg_match('/^[a-z0-9_\-]{1,32}$/i', $ownerPkg)) {
            return $this->jsonFail('归属功能包仅支持 1-32 位字母、数字、下划线或短横线');
        }
        $expireDays = max(0, (int) $this->request->post('expire_days', 0));
        $meta = [
            'is_public'     => (string) $this->request->post('is_public', '0') === '1',
            'expire_days'   => $expireDays,
            'uploader_id'   => $gate->id,
            'uploader_name' => $gate->name,
            'mime'          => (string) $file->getOriginalMime(),
        ];
        try {
            $content = (string) file_get_contents($file->getPathname());
            $origin  = (string) $file->getOriginalName();
            $row = $this->service()->store($ownerPkg, $content, $origin, $meta);
            $row['url'] = $this->service()->url((string) $row['token']);
            $this->audit($gate, 'attach.upload', (string) ($row['token'] ?? ''));
            return $this->jsonOk($row, '附件上传成功');
        } catch (\Throwable $e) {
            return $this->jsonFail('附件上传失败：' . $e->getMessage());
        }
    }

    /** 管理员删除附件（物理对象尽力删除，元数据置 deleted）。 */
    public function delete()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $token = trim((string) $this->request->post('token', ''));
        if ($token === '') {
            return $this->jsonFail('缺少 token');
        }
        try {
            $this->service()->delete($token, $gate, true);
            $this->audit($gate, 'attach.delete', $token);
            return $this->jsonOk(null, '附件已删除');
        } catch (AttachmentDenied $e) {
            return $this->jsonFail($e->getMessage(), -403);
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
    }

    // ===== AT-11 存储配置与驱动状态 =====

    public function storage()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('attach_admin/storage');
    }

    /** JSON：当前生效配置（密钥只回显布尔状态）。 */
    public function config()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk($this->service()->configState());
    }

    /** 存储驱动连通性测试（body: driver=local|qiniu，空=当前驱动）。 */
    public function driverTest()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $driver = strtolower(trim((string) $this->request->post('driver', '')));
        if ($driver !== '' && !in_array($driver, ['local', 'qiniu'], true)) {
            return $this->jsonFail('不支持的驱动：' . $driver);
        }
        $result = $this->service()->testDriver($driver);
        $this->audit($gate, 'attach.driverTest', (string) ($result['driver'] ?? ''));
        return $this->jsonOk($result, empty($result['ok']) ? ('驱动测试未通过：' . (string) ($result['message'] ?? '')) : '驱动连通正常');
    }

    // ===== AT-12 清理记录 =====

    public function clean()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('attach_admin/clean');
    }

    /** JSON：清理记录分页。 */
    public function cleanRows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        $result = $this->service()->cleanRecords($page, $size);
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** 手动触发一次过期/孤儿清理。 */
    public function cleanRun()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $result = $this->service()->clean('manual', $gate->id);
            $this->audit($gate, 'attach.clean.manual', 'scanned=' . ($result['scanned'] ?? 0) . ',deleted=' . ($result['deleted'] ?? 0));
            return $this->jsonOk($result, '清理完成');
        } catch (\Throwable $e) {
            return $this->jsonFail('清理失败：' . $e->getMessage());
        }
    }

    // ===== 内部 =====

    /** 当前默认图 Token（仅用于前端预览拼接）。 */
    private function fallbackToken(): string
    {
        return $this->service()->fallbackToken();
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
