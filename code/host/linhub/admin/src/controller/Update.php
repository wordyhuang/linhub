<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\lifecycle\PlatformUpdater;
use linhub\host\Version;

/**
 * 超管专属「平台 / workdesktop 版本检测与自升级」
 *
 *  - GET  /admin/update/versions        当前版本 + 最新版本检测（两类独立）
 *  - POST /admin/update/run             执行指定目标的服务端自动下载升级
 *  - GET  /admin/update/rollbackOptions workdesktop 可回退版本清单（本地备份 + npm 旧版）
 *  - POST /admin/update/rollback        回退 workdesktop 到指定旧版本（source=backup|npm）
 *
 * 检测配置在 .env：
 *  - UPDATE_HOST_CHECK_URL       平台自定义 JSON 检测接口（留空=平台不检测）
 *  - UPDATE_WORKDESKTOP_PACKAGE_URL  workdesktop 包发布地址（npm 包页面地址，留空=默认官方包 workdesktop-ai）
 *    平台据此自动向 npm registry 检测最新版本并取 tgz，无需单独的版本检测接口。
 */
class Update extends AdminController
{
    /**
     * 当前版本与最新版本检测结果。
     *
     * 检测失败不阻断页面：每项返回 checking=false + error 文案，前端降级为「检测失败，点击重试」。
     */
    public function versions()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $updater = new PlatformUpdater();

        return $this->jsonOk([
            'host'        => $this->buildItem($updater, PlatformUpdater::TARGET_HOST, Version::current()),
            'workdesktop' => $this->buildItem($updater, PlatformUpdater::TARGET_WORKDESKTOP, $updater->workdesktopVersion()),
            // host 升级包为 zip（需 zip 扩展）；workdesktop 升级包为 npm tgz（需 phar+zlib）
            'hostUpgradable'      => class_exists(\ZipArchive::class),
            'workdesktopUpgradable' => class_exists(\PharData::class) && extension_loaded('zlib'),
        ]);
    }

    /**
     * 执行升级：target=host|workdesktop，download_url + version 由前端回传（取自检测结果）。
     */
    public function run()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $target      = trim((string) $this->request->post('target', ''));
        $downloadUrl = trim((string) $this->request->post('download_url', ''));
        $version     = trim((string) $this->request->post('version', ''));

        if (!in_array($target, [PlatformUpdater::TARGET_HOST, PlatformUpdater::TARGET_WORKDESKTOP], true)) {
            return $this->jsonFail('非法的升级目标', 422);
        }
        if ($downloadUrl === '' || $version === '') {
            return $this->jsonFail('升级包地址或目标版本缺失', 422);
        }

        $updater = new PlatformUpdater();
        try {
            $result = $updater->upgrade($target, $downloadUrl, $version);
        } catch (\Throwable $e) {
            return $this->jsonFail('升级失败：' . $e->getMessage(), 500);
        }

        return $this->jsonOk($result, '升级成功：' . $result['from'] . ' → ' . $result['to']);
    }

    /**
     * workdesktop 可回退版本清单：本地升级前备份 + npm 历史稳定版。
     * 本地备份始终返回；npm 元数据拉取失败时 npmVersions 为空并回传 npmError，不阻断。
     */
    public function rollbackOptions()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $updater = new PlatformUpdater();
        $options = $updater->workdesktopRollbackOptions();
        $options['workdesktopUpgradable'] = class_exists(\PharData::class) && extension_loaded('zlib');

        return $this->jsonOk($options);
    }

    /**
     * 回退 workdesktop：source=backup 用本地备份还原；source=npm 下载旧版 tgz 覆盖。
     * 回退执行前服务端会再安全备份一次当前版本。
     */
    public function rollback()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $source      = trim((string) $this->request->post('source', ''));
        $ref         = trim((string) $this->request->post('ref', ''));
        $downloadUrl = trim((string) $this->request->post('download_url', ''));

        if (!in_array($source, ['backup', 'npm'], true)) {
            return $this->jsonFail('非法的回退来源', 422);
        }
        if ($ref === '') {
            return $this->jsonFail('未指定回退版本', 422);
        }
        if ($source === 'npm' && $downloadUrl === '') {
            return $this->jsonFail('npm 回退缺少下载地址', 422);
        }

        $updater = new PlatformUpdater();
        try {
            $result = $updater->rollbackWorkdesktop($source, $ref, $downloadUrl);
        } catch (\Throwable $e) {
            return $this->jsonFail('回退失败：' . $e->getMessage(), 500);
        }

        return $this->jsonOk($result, '回退成功：' . $result['from'] . ' → ' . $result['to']);
    }

    /**
     * 组装单个升级目标的检测结果项。
     *
     * @return array<string, mixed>
     */
    private function buildItem(PlatformUpdater $updater, string $target, string $currentVersion): array
    {
        $item = [
            'current'      => $currentVersion,
            'latest'       => '',
            'downloadUrl'  => '',
            'releaseNote'  => '',
            'configured'   => false,
            'hasUpdate'    => false,
            'checking'     => true,
            'error'        => '',
        ];

        try {
            $latest = $updater->checkLatest($target);
        } catch (\Throwable $e) {
            $item['checking'] = false;
            $item['error'] = $e->getMessage();
            return $item;
        }

        $item['checking'] = false;
        $item['configured'] = (bool) $latest['configured'];
        if (!$latest['configured']) {
            return $item;
        }
        $item['latest'] = $latest['version'];
        $item['downloadUrl'] = $latest['downloadUrl'];
        $item['releaseNote'] = $latest['releaseNote'];
        $item['hasUpdate'] = $latest['version'] !== '' && $updater->isNewer($target, $latest['version']);
        return $item;
    }
}
