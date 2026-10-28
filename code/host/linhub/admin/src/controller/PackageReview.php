<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\lifecycle\PackageReviewStore;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\package\PackageIntegrity;
use think\facade\Env;

/**
 * 超管专属「功能包接入审核」GUI（需求 F）—— admin 应用
 *
 *  - GET  /admin/package_review/index   审核页（空壳，数据经 JSON 接口加载）
 *  - GET  /admin/package_review/rows    暂存包列表 + 审核状态（待审/已通过/已驳回/内容已变更）
 *  - GET  /admin/package_review/detail  单个暂存包的申请内容详情（权限/菜单/工作台/界面替换/数据表/迁移脚本）
 *  - POST /admin/package_review/approve       审核通过（记录内容指纹，安装/升级门禁据此放行）
 *  - POST /admin/package_review/reject        审核驳回
 *  - POST /admin/package_review/batchApprove  批量审核通过（targets 数组，逐包落结论）
 *  - POST /admin/package_review/batchReject   批量审核驳回（targets 数组 + 统一驳回意见）
 *
 * 审核对象为暂存区（data/plugin-staging）中目录名 lgg-pkg-* 的功能包；
 * 审核记录存 data/plugin-review.json，包目录内容变化会导致指纹不一致、结论自动失效。
 */
class PackageReview extends AdminController
{
    /** 宿主根（code/host）。 */
    private function baseDir(): string
    {
        return dirname(__DIR__, 4);
    }

    /** 已安装包状态文件绝对路径。 */
    private function stateFile(): string
    {
        return $this->envPath('PACKAGE_STATE_FILE', $this->baseDir() . '/data/plugin-state.json');
    }

    /** 审核记录文件绝对路径。 */
    private function reviewFile(): string
    {
        return $this->envPath('PACKAGE_REVIEW_FILE', $this->baseDir() . '/data/plugin-review.json');
    }

    /** 可安装包暂存根目录。 */
    private function stagingRoot(): string
    {
        return $this->envPath('PACKAGE_STAGING_ROOT', $this->baseDir() . '/data/plugin-staging');
    }

    /**
     * 读取 .env 配置项（带默认值回退）；相对路径基于宿主根解析。
     */
    private function envPath(string $key, string $fallback): string
    {
        $val = Env::get($key);
        if ($val === null || (string) $val === '') {
            return $fallback;
        }
        $val = trim((string) $val);
        if (!preg_match('#^(?:[A-Za-z]:[/\\\\]|/|\\\\)#', $val)) {
            $val = $this->baseDir() . '/' . ltrim(str_replace('\\', '/', $val), '/');
        }
        return str_replace('\\', '/', $val);
    }

    /**
     * 把暂存目录名（lgg-pkg-*）解析为真实目录并做逃逸校验。
     *
     * @return string|null 合法返回绝对路径，否则 null
     */
    private function resolveStagedDir(string $target): ?string
    {
        if ($target === '' || basename($target) !== $target || !str_starts_with($target, 'lgg-pkg-')) {
            return null;
        }
        $dir = $this->stagingRoot() . '/' . $target;
        $realStaging = rtrim(str_replace('\\', '/', realpath($this->stagingRoot()) ?: ''), '/') . '/';
        $realDir     = str_replace('\\', '/', realpath($dir) ?: '');
        if ($realDir === '' || !is_dir($dir) || stripos($realDir, $realStaging) !== 0) {
            return null;
        }
        return $dir;
    }

    /** 审核页。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('package_review/index');
    }

    /** JSON：暂存包列表 + 审核状态。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        return $this->jsonOk(['list' => $this->scanStaged()]);
    }

    /** JSON：单个暂存包申请接入的内容详情。入参 target=lgg-pkg-*。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $dir = $this->resolveStagedDir((string) $this->request->get('target', ''));
        if ($dir === null) {
            return $this->jsonFail('暂存包不存在', 404);
        }

        $manifest    = $this->readReturningArray($dir . '/manifest.php');
        $short       = (string) ($manifest['short'] ?? basename($dir));
        $version     = (string) ($manifest['version'] ?? '');
        $fingerprint = PackageReviewStore::fingerprint($dir);
        $store       = new PackageReviewStore($this->reviewFile());
        $review      = $store->get($short, $version);
        $status      = $store->statusOf($short, $version, $fingerprint);
        $problems    = PackageIntegrity::check($dir);

        [$installedVersion, $installedName] = $this->installedInfo($short);
        $kind = $installedVersion === '' ? 'install' : 'upgrade';

        return $this->jsonOk([
            'target'      => basename($dir),
            'short'       => $short,
            'version'     => $version,
            'kind'        => $kind,
            'installed_version' => $installedVersion,
            'installed_name'    => $installedName,
            'valid'       => $problems === [],
            'problems'    => $problems,
            'manifest'    => [
                'name'         => (string) ($manifest['name'] ?? ''),
                'title'        => (string) ($manifest['title'] ?? ''),
                'type'         => (string) ($manifest['type'] ?? ''),
                'description'  => (string) ($manifest['description'] ?? ''),
                'access'       => (string) ($manifest['access'] ?? 'admin'),
                'dependencies' => array_values((array) ($manifest['dependencies'] ?? [])),
                'platform'     => (array) ($manifest['platform'] ?? []),
                'provides'     => array_values(array_map(
                    static fn ($v) => is_array($v) ? ((string) ($v['id'] ?? '') . ' => ' . (string) ($v['class'] ?? '')) : (string) $v,
                    (array) ($manifest['services']['provides'] ?? [])
                )),
                'requires'     => array_values((array) ($manifest['services']['requires'] ?? [])),
            ],
            // 申请接入的四类节点：读固定声明文件（与 IntegrationManager 接入时口径一致）
            'permissions'  => $this->normalizeRows($this->readReturningArray($dir . '/permissions.php'), ['code', 'title', 'description', 'group']),
            'menus'        => $this->normalizeRows($this->readReturningArray($dir . '/menus.php'), ['id', 'parent', 'title', 'route', 'icon', 'order']),
            'workspace'    => $this->readReturningArray($dir . '/workspace.php'),
            'uiReplace'    => $this->readReturningArray($dir . '/ui_replace.php'),
            'tables'       => $this->declaredTables($manifest),
            'migrations'   => $this->scanMigrations($dir, (string) ($manifest['migrations'] ?? 'migrations')),
            'fingerprint'  => $fingerprint,
            'reviewStatus' => $status,
            'review'       => $review === null ? null : [
                'status'      => (string) ($review['status'] ?? ''),
                'reviewer'    => (string) ($review['reviewer'] ?? ''),
                'reviewed_at' => (string) ($review['reviewed_at'] ?? ''),
                'comment'     => (string) ($review['comment'] ?? ''),
                'history'     => array_values((array) ($review['history'] ?? [])),
            ],
        ]);
    }

    /** JSON：审核通过。入参 target + comment（可选）。 */
    public function approve()
    {
        return $this->decide(PackageReviewStore::APPROVED);
    }

    /** JSON：审核驳回。入参 target + comment（驳回原因，可选但建议填写）。 */
    public function reject()
    {
        return $this->decide(PackageReviewStore::REJECTED);
    }

    /** JSON：批量审核通过。入参 targets（暂存目录名数组或逗号分隔）+ comment（可选）。 */
    public function batchApprove()
    {
        return $this->batchDecide(PackageReviewStore::APPROVED);
    }

    /** JSON：批量审核驳回。入参 targets（暂存目录名数组或逗号分隔）+ comment（必填，逐包记录同一意见）。 */
    public function batchReject()
    {
        return $this->batchDecide(PackageReviewStore::REJECTED);
    }

    /**
     * 落一条审核结论（approve/reject 共用）。
     * 完整性校验失败的包不允许通过；审核记录以审核时刻的内容指纹为准。
     */
    private function decide(string $status)
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $res = $this->decideOne(
            $gate,
            $status,
            (string) $this->request->post('target', ''),
            trim((string) $this->request->post('comment', ''))
        );
        if (!$res['ok']) {
            return $this->jsonFail($res['message'], $res['code']);
        }
        return $this->jsonOk(['status' => $status], $res['message']);
    }

    /**
     * 批量落审核结论（batchApprove/batchReject 共用）：逐包调用 decideOne，逐条返回成败明细。
     * 单包失败不中断整体，汇总成功/失败条数；上限 50 个防止误操作放大影响面。
     */
    private function batchDecide(string $status)
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $targets = $this->request->post('targets');
        if (is_string($targets)) {
            $targets = array_values(array_filter(array_map('trim', explode(',', $targets)), static fn ($v) => $v !== ''));
        }
        if (!is_array($targets) || $targets === []) {
            return $this->jsonFail('请先选择要审核的功能包', 422);
        }
        $targets = array_slice(array_values(array_unique(array_map('strval', $targets))), 0, 50);

        $comment = trim((string) $this->request->post('comment', ''));
        if ($status === PackageReviewStore::REJECTED && $comment === '') {
            return $this->jsonFail('批量驳回时必须填写统一的驳回原因', 422);
        }

        $results = [];
        $okCount = 0;
        foreach ($targets as $t) {
            $res = $this->decideOne($gate, $status, $t, $comment, true);
            $results[] = $res;
            if ($res['ok']) {
                $okCount++;
            }
        }
        $failCount = count($results) - $okCount;
        $action    = $status === PackageReviewStore::APPROVED ? '通过' : '驳回';

        return $this->jsonOk([
            'total'      => count($results),
            'ok_count'   => $okCount,
            'fail_count' => $failCount,
            'results'    => $results,
        ], '批量' . $action . '完成：成功 ' . $okCount . ' 个，失败 ' . $failCount . ' 个');
    }

    /**
     * 对单个暂存包落一条审核结论（单包/批量共用内核）。
     * 完整性校验失败的包不允许通过；审核记录以审核时刻的内容指纹为准。
     *
     * @return array{ok:bool, code:int, message:string, target:string, title:string}
     */
    private function decideOne(Identity $gate, string $status, string $target, string $comment, bool $batch = false): array
    {
        $base = ['ok' => false, 'code' => 200, 'message' => '', 'target' => $target, 'title' => ''];

        $dir = $this->resolveStagedDir($target);
        if ($dir === null) {
            $base['code']    = 404;
            $base['message'] = '暂存包不存在';
            return $base;
        }
        $manifest      = $this->readReturningArray($dir . '/manifest.php');
        $base['title'] = (string) ($manifest['title'] ?? '');
        $short         = (string) ($manifest['short'] ?? '');
        $version       = (string) ($manifest['version'] ?? '');
        if ($short === '' || $version === '') {
            $base['code']    = 422;
            $base['message'] = '功能包 manifest 缺少 short/version，无法审核';
            return $base;
        }
        $problems = PackageIntegrity::check($dir);
        if ($problems !== []) {
            $base['code']    = 422;
            $base['message'] = '功能包完整性校验未通过，不能审核：' . implode('；', $problems);
            return $base;
        }

        $store    = new PackageReviewStore($this->reviewFile());
        $previous = $store->get($short, $version);
        $now      = date('c');
        $entry    = [
            'status'      => $status,
            'reviewer'    => (string) $gate->name,
            'reviewed_at' => $now,
            'comment'     => $comment,
        ];

        $record = [
            'short'       => $short,
            'title'       => $base['title'],
            'version'     => $version,
            'dir'         => basename($dir),
            'fingerprint' => PackageReviewStore::fingerprint($dir),
            'status'      => $status,
            'reviewer'    => (string) $gate->name,
            'reviewed_at' => $now,
            'comment'     => $comment,
            'history'     => array_merge((array) ($previous['history'] ?? []), [$entry]),
        ];
        $store->save($short, $version, $record);

        $context = ['dir' => basename($dir), 'comment' => $comment];
        if ($batch) {
            $context['batch'] = true;
        }
        $this->bootstrap->auditTrail()->log(
            'package.review.' . $status,
            PackageReviewStore::key($short, $version),
            (string) $gate->name,
            $context
        );

        $base['ok']      = true;
        $base['message'] = $status === PackageReviewStore::APPROVED ? '已审核通过，可进行安装/升级接入' : '已驳回，该版本不可接入';
        return $base;
    }

    /** 扫描暂存区全部 lgg-pkg-* 包，附带完整性、新装/升级与审核状态。 */
    private function scanStaged(): array
    {
        $staging = $this->stagingRoot();
        if (!is_dir($staging)) {
            return [];
        }
        $store  = new PackageReviewStore($this->reviewFile());
        $out    = [];
        foreach (scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_starts_with($entry, 'lgg-pkg-') || !is_dir($staging . '/' . $entry)) {
                continue;
            }
            $dir         = $staging . '/' . $entry;
            $problems    = PackageIntegrity::check($dir);
            $mf          = $this->readReturningArray($dir . '/manifest.php');
            $short       = (string) ($mf['short'] ?? basename($entry));
            $version     = (string) ($mf['version'] ?? '');
            $fingerprint = PackageReviewStore::fingerprint($dir);
            $review      = $store->get($short, $version);
            [$installedVersion, $installedName] = $this->installedInfo($short);

            $out[] = [
                'dir'               => $entry,
                'short'             => $short,
                'title'             => (string) ($mf['title'] ?? $entry),
                'version'           => $version,
                'description'       => (string) ($mf['description'] ?? ''),
                'valid'             => $problems === [],
                'problems'          => $problems,
                'kind'              => $installedVersion === '' ? 'install' : 'upgrade',
                'installed_version' => $installedVersion,
                'installed_name'    => $installedName,
                'review_status'     => $store->statusOf($short, $version, $fingerprint),
                'reviewer'          => (string) ($review['reviewer'] ?? ''),
                'reviewed_at'       => (string) ($review['reviewed_at'] ?? ''),
                'review_comment'    => (string) ($review['comment'] ?? ''),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['short'], $b['short']));
        return $out;
    }

    /** 已安装版本信息：[version, name]，未安装返回 ['', '']。 */
    private function installedInfo(string $short): array
    {
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            if ((string) ($rec['short'] ?? '') === $short) {
                return [(string) ($rec['version'] ?? ''), (string) ($rec['name'] ?? $name)];
            }
        }
        return ['', ''];
    }

    /** 读取「返回数组」的 PHP 声明文件，失败返回空数组。 */
    private function readReturningArray(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = (static function (string $f) {
            return @include $f;
        })($path);
        return is_array($data) ? $data : [];
    }

    /** 将若干关联数组行裁剪为指定键（缺失键补空串）。 */
    private function normalizeRows(array $rows, array $keys): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($keys as $k) {
                $item[$k] = $row[$k] ?? '';
            }
            $out[] = $item;
        }
        return $out;
    }

    /** 从 manifest 中提取声明的数据表清单 [{table,type}]。 */
    private function declaredTables(array $manifest): array
    {
        $out = [];
        foreach ((array) ($manifest['resources']['database'] ?? []) as $item) {
            if (is_array($item) && !empty($item['table'])) {
                $out[] = ['table' => (string) $item['table'], 'type' => (string) ($item['type'] ?? '')];
            } elseif (is_string($item)) {
                $out[] = ['table' => $item, 'type' => ''];
            }
        }
        return $out;
    }

    /**
     * 扫描迁移目录，按版本号升序返回 [{version, files:[{name, up, down}]}]，
     * 并读取每个 .sql 脚本正文供审核人审阅（建表/改表语句是审核重点）。
     */
    private function scanMigrations(string $src, string $dir): array
    {
        $root = $src . '/' . trim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($root)) {
            return [];
        }
        $versions = scandir($root);
        $versions = $versions === false ? [] : array_filter($versions, fn ($v) => $v !== '.' && $v !== '..' && is_dir($root . '/' . $v));
        usort($versions, fn ($a, $b) => version_compare((string) $a, (string) $b));
        $out = [];
        foreach ($versions as $ver) {
            $files = scandir($root . '/' . $ver);
            $files = $files === false ? [] : array_filter($files, fn ($f) => is_string($f) && str_ends_with($f, '.sql'));
            $fileRows = [];
            foreach ($files as $f) {
                $fileRows[] = [
                    'name' => (string) $f,
                    'sql'  => (string) file_get_contents($root . '/' . $ver . '/' . $f),
                ];
            }
            usort($fileRows, static fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[] = ['version' => (string) $ver, 'files' => $fileRows];
        }
        return $out;
    }
}
