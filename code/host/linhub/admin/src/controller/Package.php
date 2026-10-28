<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\home\HomeCustomization;
use linhub\host\integration\IntegrationManager;
use linhub\host\integration\IntegrationRegistry;
use linhub\host\lifecycle\BackupManager;
use linhub\host\lifecycle\PackageInstaller;
use linhub\host\lifecycle\PackageRemover;
use linhub\host\lifecycle\PackageRollbacker;
use linhub\host\lifecycle\PackageReviewStore;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\lifecycle\PackageUpgrader;
use linhub\host\lifecycle\ToggleManager;
use linhub\host\migration\MigrationManager;
use linhub\host\migration\MigrationRunner;
use linhub\host\migration\PdoGateway;
use linhub\host\package\PackageIntegrity;
use linhub\host\package\PackagePacker;
use linhub\host\package\PackageScaffold;
use linhub\host\services\approval\ApprovalService;
use linhub\host\services\messaging\MessagingService;
use linhub\host\services\recyclebin\RecycleBinService;
use linhub\host\support\HostBootstrap;
use linhub\host\services\support\PlatformStore;
use linhub\host\services\translation\TranslationSeeder;
use linhub\host\Version;
use think\facade\Env;
use think\facade\Session;

/**
 * 超管专属「功能包管理」GUI（方案 B）—— admin 应用
 *
 *  - GET  /admin/package/index          功能包管理页（空壳，数据由 wd-* 组件经 JSON 接口加载）
 *  - GET  /admin/package/rows           总览：hostVersion + 已安装包 + 暂存可安装包
 *  - POST /admin/package/create         在暂存区新建功能包骨架
 *  - POST /admin/package/install        从暂存目录安装一个功能包
 *  - POST /admin/package/enable         启用
 *  - POST /admin/package/disable        禁用
 *  - POST /admin/package/upgrade        从暂存区同 short 目录升级
 *  - POST /admin/package/remove         删除（需 confirm，高危二次确认）
 *  - POST /admin/package/pack           打包并触发下载（二进制下载，非 JSON）
 *
 * 登录校验由 admin 应用中间件统一处理，超管校验统一走 AdminController::gate() / gateForJson()（C2）。
 *
 * 目录约定（全部可用 .env 覆盖，未配置回退默认值）：
 *  - loadRoot   = PACKAGE_LOAD_ROOT      功能包源码落地根（安装位置），默认 code/plugins
 *  - stateFile  = PACKAGE_STATE_FILE     状态记录文件，默认 code/host/data/plugin-state.json
 *  - backupRoot = PACKAGE_BACKUP_ROOT    备份根，默认 code/host/data/plugin-backups
 *  - staging    = PACKAGE_STAGING_ROOT   可安装包暂存根（下载位置），默认 code/host/data/plugin-staging
 *  - disabled   = PACKAGE_DISABLED_ROOT  禁用包隔离根，默认 code/host/data/plugin-disabled
 *  - packOut    = PACKAGE_PACKOUT_ROOT   打包输出根，默认 code/host/data/plugin-packout
 */
class Package extends AdminController
{
    /** 回退列表最多显示的版本数 */
    private const ROLLBACK_VERSION_LIMIT = 10;

    /** 功能包源码落地根（安装位置），默认 code/plugins，可用 .env 覆盖。 */
    private function loadRoot(): string
    {
        // 本类可能经 vendor/linhub/admin 这个 junction 被加载，__DIR__ 会是 vendor 符号路径，
        // 故先 realpath 解析到真实路径 .../code/host/linhub/admin/src/controller，再回溯 5 级到 code。
        $realDir = realpath(__DIR__) ?: __DIR__;
        return $this->envPath('PACKAGE_LOAD_ROOT', dirname($realDir, 5) . '/plugins');
    }

    /** 宿主根（code/host）。 */
    private function baseDir(): string
    {
        return dirname(__DIR__, 4);
    }

    /** 状态记录文件绝对路径。 */
    private function stateFile(): string
    {
        return $this->envPath('PACKAGE_STATE_FILE', $this->baseDir() . '/data/plugin-state.json');
    }

    /** 审核记录文件绝对路径（需求 F：安装/升级前须审核通过）。 */
    private function reviewFile(): string
    {
        return $this->envPath('PACKAGE_REVIEW_FILE', $this->baseDir() . '/data/plugin-review.json');
    }

    /** 备份根目录。 */
    private function backupRoot(): string
    {
        return $this->envPath('PACKAGE_BACKUP_ROOT', $this->baseDir() . '/data/plugin-backups');
    }

    /** 可安装包暂存根目录（下载位置）。 */
    private function stagingRoot(): string
    {
        return $this->envPath('PACKAGE_STAGING_ROOT', $this->baseDir() . '/data/plugin-staging');
    }

    /** 禁用包源码隔离区根目录（禁用=卸载代码至此，不可加载）。 */
    private function disabledRoot(): string
    {
        return $this->envPath('PACKAGE_DISABLED_ROOT', $this->baseDir() . '/data/plugin-disabled');
    }

    /** 无 ext-zip 时的目录包输出根（可用 .env 覆盖），持久产物属 code/host/data/plugin-packout。 */
    private function packOutRoot(): string
    {
        return $this->envPath('PACKAGE_PACKOUT_ROOT', $this->baseDir() . '/data/plugin-packout');
    }

    /**
     * 读取 .env 配置项（带默认值回退）；相对路径基于宿主根解析。
     * 未配置或配置为空时使用 $fallback。
     */
    private function envPath(string $key, string $fallback): string
    {
        $val = Env::get($key);
        if ($val === null || (string) $val === '') {
            return $fallback;
        }
        $val = trim((string) $val);
        if (!$this->isAbsolutePath($val)) {
            $val = $this->baseDir() . '/' . ltrim(str_replace('\\', '/', $val), '/');
        }
        // 相对路径（如 ../plugins）拼接后可能带中间跳转段，统一规范化为真实路径
        return $this->normalizePath($val);
    }

    /** 判断路径是否为绝对路径（Windows 盘符或 Unix 根开头的正斜杠/反斜杠）。 */
    private function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[/\\\\]|/|\\\\)#', $path) === 1;
    }

    /**
     * 规范化路径为真实路径：反斜杠统一为正斜杠；路径已存在时取 realpath；
     * 不存在时做字符串级消解（合并 ../ 与 ./ 段），消除 host/../plugins 这类中间跳转。
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $real = realpath($path);
        if ($real !== false) {
            return str_replace('\\', '/', $real);
        }
        $prefix = '';
        if ($path[0] === '/') {
            $prefix = '/';
            $path = ltrim($path, '/');
        }
        $stack = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $last = end($stack);
                if ($last !== false && $last !== '..') {
                    array_pop($stack);
                    continue;
                }
                if ($prefix === '/') {
                    continue; // Unix 根之上不再上跳
                }
            }
            $stack[] = $part;
        }
        return $prefix . implode('/', $stack);
    }

    /** 组装启用/禁用管理器：禁用移源码入隔离区并注销接入点，启用移回并重注册。 */
    private function toggleManager(): ToggleManager
    {
        return new ToggleManager(
            new PackageStateStore($this->stateFile()),
            $this->loadRoot(),
            $this->disabledRoot(),
            IntegrationManager::removeHandler(HostBootstrap::defaultRegistryFile()),
            IntegrationManager::installHandler($this->loadRoot(), HostBootstrap::defaultRegistryFile()),
        );
    }

    /** 功能包管理主页：渲染空壳，数据由 wd-* 组件经 /admin/package/rows 加载。 */
    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('package/index');
    }

    /** 功能包详情独立页：渲染空壳，数据由前端经 /admin/package/detail 按 name 加载。 */
    public function view()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('package/view');
    }

    /** JSON：总览数据（workdesktop-ai 数据源）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk([
            'hostVersion' => Version::current(),
            'installed'   => array_values($this->listInstalled()),
            'installable' => $this->listInstallable(),
            // 供前端打包前提示：未启用 zip 时不会下载，仅生成目录包
            'zipEnabled'  => class_exists(\ZipArchive::class),
            'registryUrl' => '/admin/home/dashboard',
        ]);
    }

    /** 功能包看板页：渲染空壳，数据由前端经 /admin/package/dashboardStats 加载。 */
    public function dashboard()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('package/dashboard');
    }

    /** JSON：功能包看板统计（总体统计 + 已安装明细 + 暂存明细）。 */
    public function dashboardStats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk([
            'hostVersion' => Version::current(),
            'stats'       => $this->dashboardStatsData(),
        ]);
    }

    /**
     * 汇总功能包统计数据（纯数据构建，不依赖请求上下文）。
     *
     *  - overall  总体统计：安装/启用/禁用/可升级/备份数 + 接入点合计（权限点/菜单/工作台插槽/数据表/迁移）
     *  - packages 已安装逐包明细（含接入点计数、备份、可升级、可回退）
     *  - staging  暂存区逐包明细（完整性 + 审核状态 + 与已安装版本关系）
     */
    private function dashboardStatsData(): array
    {
        $registry    = (new IntegrationRegistry(HostBootstrap::defaultRegistryFile()))->read();
        $backupMgr   = new BackupManager($this->backupRoot());
        $installable = $this->listInstallable();

        $packages = [];
        $overall  = [
            'installed' => 0, 'enabled' => 0, 'disabled' => 0, 'upgradable' => 0, 'backups' => 0,
            'permissions' => 0, 'menus' => 0, 'menusValid' => 0, 'slots' => 0,
            'tables' => 0, 'migrations' => 0, 'dependencies' => 0,
        ];
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            $short = (string) ($rec['short'] ?? '');
            $state = (string) ($rec['state'] ?? '');
            $src   = $this->resolveSourceDir($short, (array) $rec);
            if ($src === null) {
                // 禁用包源码在隔离区，回退 disabled_dir 读 manifest（与 listInstalled 同策略）
                $alt = (string) ($rec['disabled_dir'] ?? '');
                $src = $alt !== '' && is_dir($alt) ? $alt : null;
            }
            $manifest = $src !== null ? $this->readReturningArray(rtrim($src, '/\\') . '/manifest.php') : [];

            // 接入点：以注册表实际登记为准（按 short 取分区）
            $sec         = (array) ($registry[$short] ?? []);
            $secMenus    = array_values(array_filter((array) ($sec['menus'] ?? []), 'is_array'));
            $menusValid  = count(array_filter($secMenus, static fn ($m): bool => !empty($m['valid'])));
            $permsCount  = count((array) ($sec['permissions'] ?? []));
            $slotsCount  = count((array) (($sec['workspace'] ?? [])['slots'] ?? []));

            $tables     = $this->declaredTables($manifest);
            $migrations = $src !== null
                ? $this->scanMigrations($src, (string) ($manifest['migrations'] ?? 'migrations'))
                : [];
            $migFiles   = 0;
            foreach ($migrations as $mig) {
                $migFiles += count((array) ($mig['files'] ?? []));
            }
            $backups  = $name !== '' ? count($backupMgr->list((string) $name)) : 0;
            $upgrade  = $this->findUpgrade($short, (string) ($rec['version'] ?? ''));
            $rollback = $this->rollbackVersions((string) $name, (string) ($rec['version'] ?? ''));

            $packages[] = [
                'name'           => (string) ($rec['name'] ?? $name),
                'short'          => $short,
                'title'          => (string) ($manifest['title'] ?? ($rec['name'] ?? $name)),
                'version'        => (string) ($rec['version'] ?? ''),
                'state'          => $state,
                'installed_at'   => (string) ($rec['installed_at'] ?? ''),
                'access'         => (string) ($manifest['access'] ?? 'admin'),
                'permissions'    => $permsCount,
                'menus'          => count($secMenus),
                'menus_valid'    => $menusValid,
                'slots'          => $slotsCount,
                'tables'         => count($tables),
                'migrations'     => $migFiles,
                'dependencies'   => count((array) ($manifest['dependencies'] ?? [])),
                'provides'       => count((array) ($manifest['services']['provides'] ?? [])),
                'requires'       => count((array) ($manifest['services']['requires'] ?? [])),
                'subscribes'     => count((array) ($manifest['subscribes'] ?? [])),
                'backups'        => $backups,
                'rollback'       => count($rollback),
                'upgrade_version' => $upgrade['version'] ?? '',
            ];

            $overall['installed']++;
            if ($state === 'disabled') {
                $overall['disabled']++;
            } else {
                $overall['enabled']++;
            }
            $overall['upgradable']  += $upgrade !== null ? 1 : 0;
            $overall['backups']     += $backups;
            $overall['permissions'] += $permsCount;
            $overall['menus']       += count($secMenus);
            $overall['menusValid']  += $menusValid;
            $overall['slots']       += $slotsCount;
            $overall['tables']      += count($tables);
            $overall['migrations']  += $migFiles;
            $overall['dependencies'] += count((array) ($manifest['dependencies'] ?? []));
        }

        $stagingOverall = [
            'total' => count($installable),
            'valid' => count(array_filter($installable, static fn ($p): bool => !empty($p['valid']))),
            'pending'  => count(array_filter($installable, static fn ($p): bool => in_array(($p['review_status'] ?? ''), [PackageReviewStore::PENDING, PackageReviewStore::CHANGED], true))),
            'rejected' => count(array_filter($installable, static fn ($p): bool => ($p['review_status'] ?? '') === PackageReviewStore::REJECTED)),
            'upgradable' => count(array_filter($installable, static fn ($p): bool => !empty($p['installed']) && ($p['upgrade_version'] ?? '') !== '')),
        ];

        return [
            'overall'        => $overall,
            'packages'       => $packages,
            'staging'        => $installable,
            'stagingOverall' => $stagingOverall,
        ];
    }

    /**
     * 单个功能包详情（供管理页「查看」独立页加载）。
     *
     *  - GET /admin/package/detail?name=<已安装包标识>
     *
     * 汇总：manifest 声明 + state 安装状态 + composer PSR-4 + 数据表真实结构（列/索引/行数）
     * + 迁移版本 + 菜单 + 权限点。数据表结构经 PDO 实时探查，连接失败则降级为仅声明信息。
     */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $name      = trim((string) $this->request->get('name', ''));
        $store     = new PackageStateStore($this->stateFile());
        $state     = $store->read();
        $installed = $name !== '' && isset($state[$name]);
        $rec       = $installed ? $state[$name] : [];

        if ($installed) {
            $short = (string) ($rec['short'] ?? $name);
            // 已安装：源码优先真实落地位置，其次 state 记录的 source_dir
            $src = $this->resolveSourceDir($short, $rec);
        } else {
            // 未安装：按 name（形如 lgg/<short>）推导 short，回退到暂存目录 lgg-pkg-<short>
            $short = str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name;
            $src   = $this->stagingRoot() . '/lgg-pkg-' . $short;
            if ($name === '' || !is_dir($src)) {
                return $this->jsonFail('功能包不存在', 404);
            }
        }
        $manifest = $src !== null ? $this->readReturningArray($src . '/manifest.php') : [];
        $composer = $src !== null ? $this->readJsonFile($src . '/composer.json') : [];

        // 数据表：以 manifest 声明为准，逐个实时探查列/索引/行数
        $conn = $this->connect();
        $gw   = $conn['ok'] ? $conn['gateway'] : null;
        $tables = [];
        foreach ($this->declaredTables($manifest) as $t) {
            $tables[] = $this->describeTable((string) ($t['table'] ?? ''), (string) ($t['type'] ?? ''), $gw);
        }

        $migrations = $src !== null ? $this->scanMigrations($src, (string) ($manifest['migrations'] ?? 'migrations')) : [];
        $menus      = $src !== null ? $this->readReturningArray($src . '/' . ltrim((string) ($manifest['menus'] ?? ''), '/')) : [];
        $perms      = $src !== null ? $this->readReturningArray($src . '/' . ltrim((string) ($manifest['permissions'] ?? ''), '/')) : [];

        // 可回退历史版本（仅已安装包）
        $rollbackVersions = $installed
            ? $this->rollbackVersions($name, (string) ($rec['version'] ?? ''))
            : [];

        // 暂存区同 short 包：详情页「审核」按钮的对象（未安装时即当前查看的暂存包本身）
        $stagingBlock = null;
        $stagingDir   = $this->stagingRoot() . '/lgg-pkg-' . $short;
        if ($short !== '' && is_dir($stagingDir)) {
            $stProblems    = PackageIntegrity::check($stagingDir);
            $stMf          = is_file($stagingDir . '/manifest.php') ? (array) @include $stagingDir . '/manifest.php' : [];
            $stVersion     = (string) ($stMf['version'] ?? '');
            $reviewStore   = new PackageReviewStore($this->reviewFile());
            $stFingerprint = PackageReviewStore::fingerprint($stagingDir);
            $stRecord      = $stVersion !== '' ? $reviewStore->get($short, $stVersion) : null;
            $stStatus      = $stVersion !== ''
                ? $reviewStore->statusOf($short, $stVersion, $stFingerprint)
                : PackageReviewStore::PENDING;
            $stagingBlock = [
                'dir'            => 'lgg-pkg-' . $short,
                'version'        => $stVersion,
                'valid'          => $stProblems === [],
                'problems'       => $stProblems,
                'review_status'  => $stStatus,
                'reviewer'       => (string) ($stRecord['reviewer'] ?? ''),
                'reviewed_at'    => (string) ($stRecord['reviewed_at'] ?? ''),
                'review_comment' => (string) ($stRecord['comment'] ?? ''),
            ];
        }

        return $this->jsonOk([
            'name'  => $name,
            'short' => $short,
            'installed' => $installed,
            'rollback_versions' => $rollbackVersions,
            'staging' => $stagingBlock,
            'state' => [
                'version'     => (string) ($rec['version'] ?? ''),
                'state'       => (string) ($rec['state'] ?? ''),
                'installedAt' => (string) ($rec['installed_at'] ?? ''),
                // 存量记录可能带中间跳转段（如 host/../plugins），输出前统一规范化
                'sourceDir'   => $this->normalizePath((string) ($rec['source_dir'] ?? '')),
                'backupDir'   => $this->normalizePath((string) ($rec['backup_dir'] ?? '')),
                'packOutDir'  => $this->normalizePath((string) ($rec['packout_dir'] ?? '')),
            ],
            'manifest' => [
                'title'        => (string) ($manifest['title'] ?? ''),
                'type'         => (string) ($manifest['type'] ?? ''),
                'version'      => (string) ($manifest['version'] ?? ''),
                'description'  => (string) ($manifest['description'] ?? ''),
                'access'       => (string) ($manifest['access'] ?? 'admin'),
                'dependencies' => self::constraintPairs($manifest['dependencies'] ?? []),
                'platform'     => self::constraintPairs($manifest['platform'] ?? []),
                'provides'     => array_values((array) ($manifest['services']['provides'] ?? [])),
                'requires'     => array_values((array) ($manifest['services']['requires'] ?? [])),
                'subscribes'   => array_values((array) ($manifest['subscribes'] ?? [])),
                'files'        => array_values((array) ($manifest['resources']['files'] ?? [])),
            ],
            'psr4'        => (array) ($composer['autoload']['psr-4'] ?? []),
            'tables'      => $tables,
            'migrations'  => $migrations,
            'menus'       => $this->normalizeRows($menus, ['id', 'title', 'route', 'icon', 'order', 'parent']),
            'hostMenus'   => $this->hostMenuRows(),
            'mounts'      => $src !== null ? $this->mountDecls($src) : ['workspace' => [], 'uiReplace' => [], 'shortcuts' => null],
            'permissions' => $this->normalizeRows($perms, ['code', 'title', 'description', 'group']),
            'dbDiagnostic'=> $conn['ok'] ? null : $conn['diagnostic'],
        ]);
    }

    /**
     * 把 manifest 的依赖/平台约束（关联数组「名 => 约束」或纯列表）规整为行结构，
     * 供详情页依赖面板逐项展示（纯列表元素视为无版本约束）。
     *
     * @return list<array{name: string, constraint: string}>
     */
    private static function constraintPairs(mixed $deps): array
    {
        $out = [];
        foreach ((array) $deps as $k => $v) {
            if (is_int($k)) {
                $out[] = ['name' => (string) $v, 'constraint' => ''];
            } else {
                $out[] = ['name' => (string) $k, 'constraint' => (string) $v];
            }
        }
        return $out;
    }

    /**
     * 功能包声明的内容挂载点（与 IntegrationManager 接入口径一致，读包根目录固定声明文件）：
     *  - workspace.php  工作台内容插槽（K2）：zone 为挂载区锚点，缺省 home.top；
     *  - ui_replace.php 界面替换候选（L2/L3）：target 为被替换的平台页面；
     *  - shortcuts.php  首页快捷入口（A5/J6）：default/optional 引用菜单 id。
     *
     * @return array{workspace: list<array{id: string, title: string, render: string, zone: string, order: int, perms: list<string>}>, uiReplace: list<array{target: string, view: string, label: string, priority: int}>, shortcuts: array{default: list<string>, optional: list<string>}|null}
     */
    private function mountDecls(string $src): array
    {
        $slots = [];
        foreach ($this->readReturningArray($src . '/workspace.php') as $s) {
            if (!is_array($s)) {
                continue;
            }
            $slots[] = [
                'id'     => (string) ($s['id'] ?? ''),
                'title'  => (string) ($s['title'] ?? ''),
                'render' => (string) ($s['render'] ?? ''),
                'zone'   => (string) (($s['zone'] ?? '') ?: 'home.top'),
                'order'  => (int) ($s['order'] ?? 0),
                'perms'  => array_values(array_map('strval', (array) ($s['perms'] ?? []))),
            ];
        }

        $uiRows = [];
        foreach ($this->readReturningArray($src . '/ui_replace.php') as $c) {
            if (!is_array($c)) {
                continue;
            }
            $uiRows[] = [
                'target'   => (string) ($c['target'] ?? ''),
                'view'     => (string) ($c['view'] ?? ''),
                'label'    => (string) ($c['label'] ?? ''),
                'priority' => (int) ($c['priority'] ?? 0),
            ];
        }

        $sc = $this->readReturningArray($src . '/shortcuts.php');
        $shortcuts = $sc === [] ? null : [
            'default'  => array_values(array_map('strval', (array) ($sc['default'] ?? []))),
            'optional' => array_values(array_map('strval', (array) ($sc['optional'] ?? []))),
        ];

        return ['workspace' => $slots, 'uiReplace' => $uiRows, 'shortcuts' => $shortcuts];
    }

    /**
     * 宿主工作台菜单平铺表（registry.json host_admin.menus，精简为 id/parent/title/icon），
     * 供详情页菜单面板把 parent 指向工作台节点（如 menu.workspace）的包菜单挂树展示。
     *
     * @return list<array{id: string, parent: string, title: string, icon: string}>
     */
    private function hostMenuRows(): array
    {
        $registry = $this->readJsonFile(HostBootstrap::defaultRegistryFile());
        $rows = [];
        foreach ((array) ($registry['host_admin']['menus'] ?? []) as $m) {
            if (!is_array($m) || empty($m['id'])) {
                continue;
            }
            $rows[] = [
                'id'     => (string) $m['id'],
                'parent' => (string) ($m['parent'] ?? ''),
                'title'  => (string) ($m['title'] ?? $m['id']),
                'icon'   => (string) ($m['icon'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * 建立数据库网关（按 config/database.php 的 env），供详情表结构探查。
     *
     * @return array{ok:bool, gateway?:PdoGateway, diagnostic?:array<string,mixed>}
     */
    private function connect(): array
    {
        $driver  = (string) Env::get('DB_TYPE', 'mysql');
        $host    = (string) Env::get('DB_HOST', '127.0.0.1');
        $port    = (string) Env::get('DB_PORT', '3306');
        $db      = (string) Env::get('DB_NAME', '');
        $user    = (string) Env::get('DB_USER', 'root');
        $pass    = (string) Env::get('DB_PASS', '');
        $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');
        $dsn = "{$driver}:host={$host};port={$port};dbname={$db};charset={$charset}";
        try {
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return ['ok' => true, 'gateway' => new PdoGateway($pdo)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'diagnostic' => ['driver' => $driver, 'host' => $host . ':' . $port, 'db' => $db, 'error' => $e->getMessage()]];
        }
    }

    /** 解析功能包真实源码目录：优先落地位置，其次 state.source_dir；都不可用返回 null。 */
    private function resolveSourceDir(string $short, array $rec): ?string
    {
        $landed = $this->loadRoot() . '/' . $short;
        if (is_dir($landed)) {
            return $landed;
        }
        $recorded = (string) ($rec['source_dir'] ?? '');
        foreach ([$this->baseDir() . '/' . $recorded, dirname($this->baseDir()) . '/' . $recorded] as $cand) {
            $cand = str_replace('\\', '/', $cand);
            if (is_dir($cand)) {
                return $cand;
            }
        }
        return null;
    }

    /** 读取并解析 JSON 文件，失败返回空数组。 */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $arr = $raw === false ? [] : json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** 读取「返回数组」的 PHP 声明文件（manifest/menus/permissions），失败返回空数组。 */
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
     * 探查一张表：是否存在、列、索引、行数。
     *
     * @param PdoGateway|null $gw 数据库网关；为 null（连接失败）时仅回显声明
     */
    private function describeTable(string $table, string $type, ?PdoGateway $gw): array
    {
        if ($gw === null) {
            return ['table' => $table, 'type' => $type, 'exists' => null, 'columns' => [], 'indexes' => [], 'rowCount' => null];
        }
        try {
            $exists = in_array(strtolower($table), $gw->tables(), true);
            if (!$exists) {
                return ['table' => $table, 'type' => $type, 'exists' => false, 'columns' => [], 'indexes' => [], 'rowCount' => null];
            }
            $rows = $gw->query("SELECT COUNT(*) AS c FROM `{$table}`");
            return [
                'table'    => $table,
                'type'     => $type,
                'exists'   => true,
                'columns'  => $gw->columns($table),
                'indexes'  => $gw->indexes($table),
                'rowCount' => isset($rows[0]['c']) ? (int) $rows[0]['c'] : null,
            ];
        } catch (\Throwable $e) {
            return ['table' => $table, 'type' => $type, 'exists' => null, 'columns' => [], 'indexes' => [], 'rowCount' => null, 'error' => $e->getMessage()];
        }
    }

    /** 扫描迁移目录，按版本号升序返回 [{version,files:[.sql 文件名...]}]。 */
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
            $files = $files === false ? [] : array_values(array_filter($files, fn ($f) => is_string($f) && str_ends_with($f, '.sql')));
            $out[] = ['version' => (string) $ver, 'files' => $files];
        }
        return $out;
    }

    /** 将若干关联数组行裁剪为指定键（缺失键补空串），供前端直接渲染。 */
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

    /** JSON：新建功能包骨架：按协议生成 lgg-pkg-<short>/ 到暂存区，可直接校验、打包、安装。 */
    public function create()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $short   = (string) $this->request->post('short', '');
        $title   = (string) $this->request->post('title', '');
        $version = (string) $this->request->post('version', '0.1.0');
        $type    = (string) $this->request->post('type', 'app');
        if ($type !== 'app' && $type !== 'lib') {
            $type = 'app';
        }

        try {
            $dir = PackageScaffold::scaffold($short, $title, $this->stagingRoot(), $version, $type);
            return $this->jsonOk(['dir' => basename($dir)], '已生成功能包骨架：' . basename($dir));
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** JSON：从暂存目录安装功能包。 */
    public function install()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $target = (string) $this->request->post('target', '');
        if ($target === '' || !str_starts_with($target, 'lgg-pkg-')) {
            return $this->jsonFail('无效的安装目标', 422);
        }
        $dir = $this->stagingRoot() . '/' . $target;

        // 目标必须为暂存根下的目录（Windows 反斜杠统一为正斜杠后再比对，杜绝路径逃逸）
        $realStaging = rtrim(str_replace('\\', '/', realpath($this->stagingRoot()) ?: ''), '/') . '/';
        $realDir     = str_replace('\\', '/', realpath($dir) ?: '');
        if ($realDir === '' || !is_dir($dir) || stripos($realDir, $realStaging) !== 0) {
            return $this->jsonFail('无效的安装目标', 422);
        }

        // 需求 F 接入审核门禁：该版本必须已审核通过且包内容指纹未变
        $gateMsg = $this->reviewGateMessage($dir);
        if ($gateMsg !== null) {
            return $this->jsonFail($gateMsg, 423);
        }

        try {
            // 迁移槽位：连接数据库并注入建表执行器（缺失会导致建表被静默跳过，表不生成）
            $conn = $this->connect();
            if (!$conn['ok']) {
                return $this->jsonFail('安装前无法连接数据库，不能执行建表迁移', 500);
            }
            $dbGate = $conn['gateway'];
            $migrate = (new MigrationManager($this->loadRoot(), $dbGate))->installHandler();
            $result = (new PackageInstaller(
                new PackageStateStore($this->stateFile()),
                new BackupManager($this->backupRoot()),
                $this->loadRoot(),
                Version::current(),
                $migrate,
                IntegrationManager::installHandler($this->loadRoot(), HostBootstrap::defaultRegistryFile(), [], $dbGate),
            ))->install($dir);
            $name = (string) ($result['record']['name'] ?? $target);
            $short = (string) ($result['record']['short'] ?? '');
            if ($short === '') {
                $short = substr((string) $target, strlen('lgg-pkg-'));
            }
            $version = (string) ($result['record']['version'] ?? '');
            MessagingService::broadcastLifecycle(MessagingService::EVENT_INSTALLED, $short, ['version' => $version]);
            $this->appendDefaultShortcuts($short);
            return $this->jsonOk(['name' => $name], '已安装：' . $name);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * 把该包 shortcuts.php 声明的「默认显示卡片」追加到已定制用户的首页定制列表。
     * 未定制用户无需落盘：其默认集合在渲染时自动包含新声明。
     */
    private function appendDefaultShortcuts(string $short): void
    {
        $short = trim($short);
        if ($short === '') {
            return;
        }
        $defaults = $this->bootstrap->shortcuts()->defaultIds($short);
        if ($defaults === []) {
            return;
        }
        (new HomeCustomization($this->bootstrap->dataRoot() . '/home/custom.json'))->appendDefaults($defaults);
    }

    /** JSON：启用功能包。 */
    public function enable()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $this->toggleManager()->enable((string) $this->request->post('name', ''));
            return $this->jsonOk(null, '已启用');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** JSON：禁用功能包。 */
    public function disable()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $this->toggleManager()->disable((string) $this->request->post('name', ''));
            return $this->jsonOk(null, '已禁用（代码已卸载，不可用）');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** JSON：升级功能包：从暂存区同 short 目录取新版，保持启用/禁用状态。 */
    public function upgrade()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $name  = (string) $this->request->post('name', '');
            $rec   = (new PackageStateStore($this->stateFile()))->get($name);
            $short = (string) ($rec['short'] ?? '');
            $dir   = $this->stagingRoot() . '/lgg-pkg-' . $short;
            if ($short === '' || !is_dir($dir)) {
                throw new \RuntimeException('暂存区不存在可升级的新版包');
            }
            // 需求 F 接入审核门禁：升级用的暂存版本同样必须已审核通过且指纹未变（423 与 install 一致）
            $gateMsg = $this->reviewGateMessage($dir);
            if ($gateMsg !== null) {
                return $this->jsonFail($gateMsg, 423);
            }
            // 迁移槽位：连接数据库并注入升级执行器（缺失会导致新版本建表被静默跳过）
            $conn = $this->connect();
            if (!$conn['ok']) {
                return $this->jsonFail('升级前无法连接数据库，不能执行建表迁移', 500);
            }
            $dbGate = $conn['gateway'];
            $migrate = (new MigrationManager($this->loadRoot(), $dbGate))->upgradeHandler();
            $result = (new PackageUpgrader(
                new PackageStateStore($this->stateFile()),
                new BackupManager($this->backupRoot()),
                $this->loadRoot(),
                Version::current(),
                $migrate,
                IntegrationManager::upgradeHandler($this->loadRoot(), HostBootstrap::defaultRegistryFile(), [], $dbGate),
            ))->upgrade($name, $dir);
            $to = (string) ($result['record']['version'] ?? '');
            MessagingService::broadcastLifecycle(MessagingService::EVENT_UPGRADED, $short, ['from_version' => (string) ($rec['version'] ?? ''), 'to_version' => $to]);
            $this->appendDefaultShortcuts($short);
            return $this->jsonOk(['version' => $to], '已升级到 v' . $to);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * JSON：回退功能包到指定历史版本。
     * 入参 name + backupPath（来自 rows 返回的 rollback_versions）；
     * backupPath 必须位于备份根/<name>/ 之下，杜绝路径逃逸。回退保持启用/禁用状态。
     */
    public function rollback()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $name       = (string) $this->request->post('name', '');
        $backupPath = trim((string) $this->request->post('backupPath', ''));
        if ($name === '' || $backupPath === '') {
            return $this->jsonFail('缺少回退目标或备份路径', 422);
        }

        // 备份路径必须真实落在 <backupRoot>/<name>/ 之下（统一分隔符后比对前缀）
        $allowed = rtrim(str_replace('\\', '/', realpath($this->backupRoot() . '/' . $name) ?: ''), '/') . '/';
        $realBp  = str_replace('\\', '/', realpath($backupPath) ?: '');
        if ($realBp === '' || stripos($realBp, $allowed) !== 0) {
            return $this->jsonFail('无效的备份路径', 422);
        }

        try {
            $result = (new PackageRollbacker(
                new PackageStateStore($this->stateFile()),
                new BackupManager($this->backupRoot()),
                $this->loadRoot(),
            ))->rollback($name, $backupPath);
            $to = (string) ($result['record']['version'] ?? '');
            return $this->jsonOk(['version' => $to], '已回退到 v' . $to);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * JSON：卸载变更预览（高危操作前让用户在弹窗里确认"会被改变什么"）。
     *
     * 返回待删除源码路径、将被摘除的接入点（菜单/权限/服务）、迁移版本，
     * 以及该包声明数据表的当前状态（是否存在/行数），供前端确认框渲染并决定数据表保留或回滚删除。
     */
    public function removePreview()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $name  = trim((string) $this->request->get('name', ''));
        $store = new PackageStateStore($this->stateFile());
        $rec   = $store->get($name);
        if ($rec === null) {
            return $this->jsonFail('功能包不存在或未安装', 404);
        }
        $short = (string) ($rec['short'] ?? $name);
        $src   = $this->resolveSourceDir($short, $rec);
        $manifest = $src !== null ? $this->readReturningArray($src . '/manifest.php') : [];

        // 数据表：以 manifest 声明为准，逐个探查是否存在/行数（连接失败则降级为仅声明）
        $conn   = $this->connect();
        $gw     = $conn['ok'] ? $conn['gateway'] : null;
        $tables = [];
        foreach ($this->declaredTables($manifest) as $t) {
            $tables[] = $this->describeTable((string) ($t['table'] ?? ''), (string) ($t['type'] ?? ''), $gw);
        }

        // 该包已应用（up）的迁移版本：drop 时按倒序回滚这些版本
        $appliedVersions = [];
        if ($conn['ok'] && $src !== null) {
            try {
                $mm = new MigrationManager($this->loadRoot(), $gw);
                $appliedVersions = $mm->table()->appliedVersions($name);
            } catch (\Throwable) {
                $appliedVersions = [];
            }
        }

        $menus = $src !== null ? $this->readReturningArray($src . '/' . ltrim((string) ($manifest['menus'] ?? ''), '/')) : [];
        $perms = $src !== null ? $this->readReturningArray($src . '/' . ltrim((string) ($manifest['permissions'] ?? ''), '/')) : [];

        return $this->jsonOk([
            'name'   => $name,
            'short'  => $short,
            'version'=> (string) ($rec['version'] ?? ''),
            'title'  => (string) ($manifest['title'] ?? $short),
            // 将被删除的源码路径（相对 code 展示），输出前统一规范化
            'source_dir' => $src !== null ? $this->normalizePath($src) : '',
            // 将被摘除的接入点统计
            'counts' => [
                'menus'       => count(array_filter($menus, 'is_array')),
                'permissions' => count(array_filter($perms, 'is_array')),
                'provides'    => count((array) ($manifest['services']['provides'] ?? [])),
                'tables'      => count($tables),
            ],
            // 迁移：已应用版本（drop 将回滚）
            'applied_migrations' => $appliedVersions,
            // 数据表当前状态
            'tables'        => $tables,
            'db_connectable'=> $conn['ok'],
        ]);
    }

    /**
     * JSON：卸载功能包（高危，需 confirm 二次确认）。
     *
     * @param string $name 功能包名称
     * @param string $confirm 二次确认标记，必须为 1/true
     * @param string $data_handling 数据表处理：keep（默认，保留数据表不动）/ drop（回滚删除该包建的表）
     *
     * 执行顺序（drop 时关键）：
     *  1. 按已应用版本倒序执行 down 迁移回滚（此时源码仍在，down 脚本可用）；
     *  2. 删除源码 + 摘除接入点 + 移除状态记录。
     */
    public function remove()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $name    = trim((string) $this->request->post('name', ''));
        $confirm = trim((string) $this->request->post('confirm', ''));
        $handle  = trim((string) $this->request->post('data_handling', 'keep'));

        if (!in_array($confirm, ['1', 'true', 'yes'], true)) {
            return $this->jsonFail('删除为高危操作，请先二次确认');
        }
        if (!in_array($handle, ['keep', 'drop'], true)) {
            return $this->jsonFail('无效的数据表处理方式', 422);
        }

        $store = new PackageStateStore($this->stateFile());
        $rec   = $store->get($name);
        if ($rec === null) {
            return $this->jsonFail('功能包不存在或未安装', 404);
        }
        $short    = (string) ($rec['short'] ?? $name);
        $src      = $this->resolveSourceDir($short, $rec);
        $manifest = $src !== null ? $this->readReturningArray($src . '/manifest.php') : [];

        // drop：先回滚迁移（源码删除前执行，保证 down 脚本可用），否则拒绝
        if ($handle === 'drop') {
            $conn = $this->connect();
            if (!$conn['ok']) {
                return $this->jsonFail('无法连接数据库，不能回滚删除数据表');
            }
            $gw = $conn['gateway'];
            try {
                $mm   = new MigrationManager($this->loadRoot(), $gw);
                $done = $mm->table()->appliedVersions($name);
                // 倒序回滚：最新版本先撤销
                $done = array_reverse($done);
                $runner = new MigrationRunner($gw);
                $sub = (string) ($manifest['migrations'] ?? 'migrations');
                // 仅当 src 存在且确有该版本目录时才回滚；版本目录缺失时跳过以容忍不完整包
                foreach ($done as $version) {
                    $dir = $this->loadRoot() . '/' . $short . '/' . trim($sub, '/') . '/' . $version;
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $runner->rollback($manifest, $this->loadRoot(), $version, $sub);
                }
            } catch (\Throwable $e) {
                return $this->jsonFail('回滚数据失败，已中止卸载：' . $e->getMessage());
            }
        }

        try {
            (new PackageRemover($store, $this->loadRoot()))->remove($name, true);

            // 注销该包的接入点（菜单/权限/工作区/界面替换）：与禁用路径（toggleManager）保持一致，
            // 否则源码与状态虽已删除，注册表中的菜单仍会注入导航，出现"卸载后菜单还在"的幽灵菜单。
            IntegrationManager::removeHandler(HostBootstrap::defaultRegistryFile())($short);

            // 翻译词条清除（卸载即删该包 namespace）；DB 不可用时容忍为孤儿行，不阻断卸载
            $purged = 0;
            $purgeError = '';
            try {
                $conn = $this->connect();
                if ($conn['ok']) {
                    $purged = (new TranslationSeeder(new PlatformStore($conn['gateway'])))->purgePackage($short);
                }
            } catch (\Throwable $e) {
                $purgeError = $e->getMessage();
            }

            // 回收站条目打孤儿态（数据保留，重装同名包自动接管）；DB 不可用时容忍失败，不阻断卸载
            $orphaned = 0;
            $orphanError = '';
            $approvalOrphaned = 0;
            $approvalOrphanError = '';
            try {
                $conn = $this->connect();
                if ($conn['ok']) {
                    $orphaned = (new RecycleBinService(new PlatformStore($conn['gateway'])))->orphanPackage($short);
                    // 审批单据打孤儿态：pending 单据冻结，已终态单据与动作流水永久保留
                    $approvalOrphaned = (new ApprovalService(
                        new PlatformStore($conn['gateway'])
                    ))->orphanPackage($short);
                }
            } catch (\Throwable $e) {
                $orphanError = $e->getMessage();
                $approvalOrphanError = $e->getMessage();
            }

            MessagingService::broadcastLifecycle(MessagingService::EVENT_REMOVED, $short, ['name' => $name]);
            return $this->jsonOk([
                'name'  => $name,
                'data_handling' => $handle,
                'translations_cleared' => $purged,
                'translation_error' => $purgeError,
                'recycle_bin_orphaned' => $orphaned,
                'recycle_bin_error' => $orphanError,
                'approval_orphaned' => $approvalOrphaned,
                'approval_error' => $approvalOrphanError,
            ], '功能包已卸载' . ($handle === 'drop' ? '，数据表已回滚删除' : '，数据表已保留')
                . ($purged > 0 ? "，已清除 {$purged} 条翻译词条" : '')
                . ($orphaned > 0 ? "，回收站 {$orphaned} 条数据已转托管" : '')
                . ($approvalOrphaned > 0 ? "，审批单据 {$approvalOrphaned} 条已冻结" : ''));
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /**
     * JSON：删除暂存（可安装、未安装）功能包目录，需前端 confirm。
     * 仅允许删除暂存根下的 lgg-pkg-* 顶层目录，杜绝路径穿越。
     */
    public function removeStaged()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $target = (string) $this->request->post('target', '');
        if ($target === '' || basename($target) !== $target || !str_starts_with($target, 'lgg-pkg-')) {
            return $this->jsonFail('无效的目标', 422);
        }
        $dir = $this->stagingRoot() . '/' . $target;

        // 目标必须为暂存根下的目录（统一路径分隔符后再比对，杜绝路径逃逸）
        $realStaging = rtrim(str_replace('\\', '/', realpath($this->stagingRoot()) ?: ''), '/') . '/';
        $realDir     = str_replace('\\', '/', realpath($dir) ?: '');
        if ($realDir === '' || !is_dir($dir) || stripos($realDir, $realStaging) !== 0) {
            return $this->jsonFail('无效的目标', 422);
        }

        try {
            $this->deleteDir($dir);
            return $this->jsonOk(['dir' => $target], '已删除暂存包：' . $target);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 递归删除目录（含子目录与文件）。 */
    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * 打包功能包并直接触发下载：对指定 lgg-pkg-<short> 目录（暂存区或加载目录）打 zip；
     * 无 ext-zip 时退化为生成目录包进 data/plugin-packout。
     * 打包前自动跑 manifest 校验（G1），校验不过或不含 manifest 则拒绝。
     * 该端点为二进制下载，不走 JSON；失败时重定向回首页由消息提示。
     */
    public function pack()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        $target = (string) $this->request->post('target', '');
        // 目标必须是形如 lgg-pkg-* 的顶层目录名，杜绝路径穿越
        if ($target === '' || basename($target) !== $target || !str_starts_with($target, 'lgg-pkg-')) {
            Session::flash('linhub_msg', ['type' => 'error', 'text' => '无效的打包目标']);
            return redirect('/admin/package/index');
        }

        foreach ([$this->stagingRoot(), $this->loadRoot()] as $root) {
            $dir = rtrim($root, '/\\') . '/' . $target;
            if (!is_dir($dir) || !is_file(rtrim($dir, '/\\') . '/manifest.php')) {
                continue;
            }
            try {
                $packShort = substr((string) $target, strlen('lgg-pkg-'));
                if (class_exists(\ZipArchive::class)) {
                    $zip = PackagePacker::pack($dir, $this->stagingRoot());
                    MessagingService::broadcastLifecycle(MessagingService::EVENT_PACKED, $packShort);
                    return download($zip, basename($zip));
                }
                $pack = rtrim($this->packOutRoot(), '/\\') . '/' . $target;
                PackagePacker::packToDir($dir, $pack);
                MessagingService::broadcastLifecycle(MessagingService::EVENT_PACKED, $packShort);
                Session::flash('linhub_msg', ['type' => 'success', 'text' => '未启用 ext-zip，已生成目录包：' . $pack]);
                return redirect('/admin/package/index');
            } catch (\Throwable $e) {
                Session::flash('linhub_msg', ['type' => 'error', 'text' => $e->getMessage()]);
                return redirect('/admin/package/index');
            }
        }

        Session::flash('linhub_msg', ['type' => 'error', 'text' => '未找到待打包的功能包']);
        return redirect('/admin/package/index');
    }

    /** 已安装功能包列表（补充标题/描述；禁用包源码在隔离区，回退 disabled_dir 读 manifest）。 */
    private function listInstalled(): array
    {
        $rows = [];
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            $title = (string) ($rec['name'] ?? $name);
            $desc  = '';
            $src   = (string) ($rec['source_dir'] ?? '');
            if (!is_file(rtrim($src, '/\\') . '/manifest.php')) {
                $alt = (string) ($rec['disabled_dir'] ?? '');
                if ($alt !== '') {
                    $src = $alt;
                }
            }
            $mf    = $src !== '' && is_file(rtrim($src, '/\\') . '/manifest.php')
                ? (array) @include rtrim($src, '/\\') . '/manifest.php'
                : [];
            if ($mf !== []) {
                $title = (string) ($mf['title'] ?? $title);
                $desc  = (string) ($mf['description'] ?? '');
            }
            // 可升级：暂存区存在同 short 且版本不同的包
            $upgrade = $this->findUpgrade((string) ($rec['short'] ?? ''), (string) ($rec['version'] ?? ''));
            // 可回退：备份区存在版本不同于当前版本的历史备份
            $rollbackVersions = $this->rollbackVersions($name, (string) ($rec['version'] ?? ''));
            $rows[] = [
                'name'            => (string) ($rec['name'] ?? $name),
                'short'           => (string) ($rec['short'] ?? ''),
                'title'           => $title,
                'description'     => $desc,
                'version'         => (string) ($rec['version'] ?? ''),
                'state'           => (string) ($rec['state'] ?? ''),
                'installed_at'    => (string) ($rec['installed_at'] ?? ''),
                'upgrade_dir'     => $upgrade['dir'] ?? '',
                'upgrade_version' => $upgrade['version'] ?? '',
                'rollback_versions' => $rollbackVersions,
            ];
        }
        return array_values($rows);
    }

    /** 在暂存区查找同 short 且版本不同的可升级包。 */
    private function findUpgrade(string $short, string $curVersion): ?array
    {
        if ($short === '') {
            return null;
        }
        $dir = $this->stagingRoot() . '/lgg-pkg-' . $short;
        $mf  = is_file($dir . '/manifest.php') ? (array) @include $dir . '/manifest.php' : [];
        $ver = (string) ($mf['version'] ?? '');
        if ($mf === [] || $ver === '' || $ver === $curVersion) {
            return null;
        }
        return ['dir' => basename($dir), 'version' => $ver];
    }

    /**
     * 需求 F 接入审核门禁：暂存包该版本未获「已通过且指纹一致」审核时返回拒绝原因，通过返回 null。
     */
    private function reviewGateMessage(string $dir): ?string
    {
        $mf  = is_file($dir . '/manifest.php') ? (array) @include $dir . '/manifest.php' : [];
        $short   = (string) ($mf['short'] ?? '');
        $version = (string) ($mf['version'] ?? '');
        if ($short === '' || $version === '') {
            return '功能包 manifest 缺少 short/version，无法校验审核状态';
        }
        $store       = new PackageReviewStore($this->reviewFile());
        $fingerprint = PackageReviewStore::fingerprint($dir);
        if ($store->isApproved($short, $version, $fingerprint)) {
            return null;
        }
        $status = $store->statusOf($short, $version, $fingerprint);
        $reason = match ($status) {
            PackageReviewStore::REJECTED => '该版本已被审核驳回',
            PackageReviewStore::CHANGED  => '审核通过后包内容已变更，需重新审核',
            default                      => '该版本尚未通过功能包接入审核',
        };
        return $reason . '，请先在「功能包审核」中完成审核';
    }

    /**
     * 列出可回退的历史版本：扫描备份目录，按备份内 manifest 的版本分组，
     * 排除与当前版本相同的备份；同一版本取时间最新的一份备份，按备份时间倒序返回。
     *
     * @return list<array{version:string, path:string, tag:string, time:string}>
     */
    private function rollbackVersions(string $name, string $currentVersion): array
    {
        $bm      = new BackupManager($this->backupRoot());
        $grouped = [];
        foreach ($bm->list($name) as $b) {
            $path = (string) ($b['path'] ?? '');
            $mf   = $path . '/manifest.php';
            if (!is_file($mf)) {
                continue;
            }
            $m = @include $mf;
            if (!is_array($m)) {
                continue;
            }
            $version = (string) ($m['version'] ?? '');
            if ($version === '' || $version === $currentVersion) {
                continue;
            }
            $entry = basename($path);
            $time  = $this->backupTime($entry);
            // 同版本保留时间最新的备份
            if (!isset($grouped[$version]) || strcmp($time, $grouped[$version]['time']) > 0) {
                $grouped[$version] = [
                    'version' => $version,
                    'path'    => $path,
                    'tag'     => (string) ($b['tag'] ?? ''),
                    'time'    => $time,
                ];
            }
        }
        $out = array_values($grouped);
        usort($out, static fn ($a, $b) => strcmp($b['time'], $a['time']));
        // 只暴露最近 10 个可回退版本，避免历史过多
        return array_slice($out, 0, self::ROLLBACK_VERSION_LIMIT);
    }

    /**
     * 从备份目录名（如 pre-upgrade_20261009_125537_69）解析备份时间，
     * 返回可比较/可展示的 YYYY-MM-DD HH:mm:ss，解析失败返回目录名。
     */
    private function backupTime(string $entry): string
    {
        if (preg_match('#(\d{8})_(\d{6})#', $entry, $m)) {
            return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)
                . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
        }
        return $entry;
    }

    /** 暂存可安装功能包列表（目录名须 lgg-pkg-*，且通过完整性校验）。 */
    private function listInstallable(): array
    {
        $staging = $this->stagingRoot();
        if (!is_dir($staging)) {
            return [];
        }
        // 已安装包 short => [version, name] 映射，用于判断暂存包是否已安装及可升级
        $installedMap = [];
        foreach ((new PackageStateStore($this->stateFile()))->read() as $name => $rec) {
            $installedMap[(string) ($rec['short'] ?? '')] = [
                'version' => (string) ($rec['version'] ?? ''),
                'name'    => (string) ($rec['name'] ?? $name),
            ];
        }
        // 需求 F：附带审核状态，前端据此放行/禁用安装与升级按钮
        $reviewStore = new PackageReviewStore($this->reviewFile());
        $out = [];
        foreach (scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_starts_with($entry, 'lgg-pkg-')) {
                continue;
            }
            $dir = $staging . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $problems = PackageIntegrity::check($dir);
            $mf       = is_file($dir . '/manifest.php') ? (array) @include $dir . '/manifest.php' : [];
            $short    = (string) ($mf['short'] ?? basename($entry));
            $stagingVersion = (string) ($mf['version'] ?? '');
            $installedInfo = $installedMap[$short] ?? ['version' => '', 'name' => ''];
            $installedVersion = $installedInfo['version'];
            $installedName = $installedInfo['name'];
            // 可升级：已安装且暂存区版本与已安装版本不同
            $upgradeVersion = ($installedVersion !== '' && $stagingVersion !== '' && $stagingVersion !== $installedVersion)
                ? $stagingVersion
                : '';
            $fingerprint    = PackageReviewStore::fingerprint($dir);
            $reviewRecord   = ($short !== '' && $stagingVersion !== '') ? $reviewStore->get($short, $stagingVersion) : null;
            $reviewStatus   = ($short !== '' && $stagingVersion !== '')
                ? $reviewStore->statusOf($short, $stagingVersion, $fingerprint)
                : PackageReviewStore::PENDING;
            $out[] = [
                'dir'             => $entry,
                'short'           => $short,
                'title'           => (string) ($mf['title'] ?? $entry),
                'version'         => $stagingVersion,
                'description'     => (string) ($mf['description'] ?? ''),
                'valid'           => $problems === [],
                'problems'        => $problems,
                'installed'       => $installedVersion !== '',
                'installed_version' => $installedVersion,
                'installed_name'  => $installedName,
                'upgrade_version' => $upgradeVersion,
                // 需求 F：审核状态（pending/approved/rejected/changed）
                'review_status'   => $reviewStatus,
                'reviewer'        => (string) ($reviewRecord['reviewer'] ?? ''),
                'reviewed_at'     => (string) ($reviewRecord['reviewed_at'] ?? ''),
                'review_comment'  => (string) ($reviewRecord['comment'] ?? ''),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['short'], $b['short']));
        return $out;
    }
}