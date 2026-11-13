<?php

declare(strict_types=1);

namespace linhub\host\integration;

use linhub\host\migration\DbGateway;
use linhub\host\services\PlatformDataImporter;
use linhub\host\services\approval\ApprovalFlowSeeder;
use linhub\host\services\approval\ApprovalService;
use linhub\host\services\printtpl\PrintTplService;
use linhub\host\services\recyclebin\RecycleBinService;
use linhub\host\services\scheduler\SchedulerService;
use linhub\host\services\support\PlatformStore;
use linhub\host\services\translation\TranslationSeeder;

/**
 * 插件接入点编排器（T4.1–T4.7 聚合）
 *
 * 统一封装权限/菜单/工作台/界面替换四个注册表，提供"按包安装/覆盖/清理"的接入编排，
 * 并向 PackageInstaller/Upgrader 的 integrate 槽位提供可调用 handler。
 * 读取已在 loadRoot/{short}/ 落地源码中的 permissions.php / menus.php / workspace.php / ui_replace.php / shortcuts.php 并登记到注册文件。
 * 另在安装/升级时导入包内 translations/ 翻译种子（安装 upsert、升级全量覆盖）、data/ 目录的数据随包服务数据（按 manifest.data 声明，见 PlatformDataImporter）与 resource/approvals/ 审批流程声明（统一 upsert；未注入 DB 网关时两者均跳过）。
 */
final class IntegrationManager
{
    private IntegrationRegistry $store;
    /** @var list<string> 平台可替换页面清单 */
    private array $replaceablePages;
    /** @var callable|null 路由存在性判定：function(string $route): bool */
    private $routeExists;
    /** @var callable|null 工作台插槽权限回调（S5） */
    private $permChecker;
    /** @var DbGateway|null 翻译种子导入用数据库网关（未注入则跳过种子步骤） */
    private ?DbGateway $db;
    /** @var list<string> 最近一次装配的警告 */
    private array $warnings = [];

    public function __construct(
        IntegrationRegistry $store,
        array $replaceablePages = [],
        ?callable $routeExists = null,
        ?callable $permChecker = null,
        ?DbGateway $db = null,
    ) {
        $this->store = $store;
        $this->replaceablePages = $replaceablePages;
        $this->routeExists = $routeExists;
        $this->permChecker = $permChecker;
        $this->db = $db;
    }

    /**
     * 按 manifest 装配一个包的全部接入点（源码已落地到 $loadRoot/{short}/）。
     *
     * @param string $loadRoot 宿主加载目录（源码落地根）
     */
    public function install(string $loadRoot, array $manifest): void
    {
        $this->integrate($loadRoot, $manifest, false);
    }

    /** 覆盖式更新：与 install 相同（注册表按分区覆盖写入），翻译种子按全量覆盖重导。 */
    public function upgrade(string $loadRoot, array $manifest): void
    {
        $this->integrate($loadRoot, $manifest, true);
    }

    private function integrate(string $loadRoot, array $manifest, bool $overwrite): void
    {
        $short = (string) ($manifest['short'] ?? '');
        if ($short === '' || $short === IntegrationRegistry::GLOBAL) {
            throw new \RuntimeException('manifest 缺失合法 short，无法接入');
        }
        $loadRoot = rtrim($loadRoot, '/\\');
        $root = $loadRoot . '/' . $short;

        $this->warnings = array_merge(
            $this->warnings,
            (new PermissionRegistry($this->store))->register($short, $this->loadDecl($root, 'permissions.php')),
            (new MenuRegistry($this->store))->register($short, $this->loadDecl($root, 'menus.php'), $this->routeExists),
        );

        $ws = $this->loadDecl($root, 'workspace.php');
        if ($ws !== []) {
            (new WorkspaceRegistry($this->store, $this->permChecker))->registerSlots($short, $ws);
        }
        $ui = $this->loadDecl($root, 'ui_replace.php');
        if ($ui !== []) {
            (new UiReplaceRegistry($this->store, $this->replaceablePages))->registerCandidates($short, $ui);
        }

        // 首页快捷入口声明（default ≤3 / optional ≤20，超限截断并告警）
        $sc = $this->loadDecl($root, 'shortcuts.php');
        if ($sc !== []) {
            $this->warnings = array_merge(
                $this->warnings,
                (new ShortcutRegistry($this->store))->register($short, $sc),
            );
        }

        // 翻译种子：安装 upsert、升级全量覆盖（未注入 DB 网关时跳过，容忍无 DB 环境）
        if ($this->db !== null) {
            $seeder = new TranslationSeeder(new PlatformStore($this->db));
            if ($overwrite) {
                $seeder->overwritePackage($short, $root);
            } else {
                $seeder->importPackage($short, $root);
            }
        }

        // 数据随包导入（P1）：种子之后、能力接管之前，按 manifest.data 声明导入包内 data/ 目录的服务数据
        // DB 不可用或包内无 data/index.json 时静默跳过；单服务失败仅告警不阻断（详情见 PlatformDataImporter::importFromDir）
        try {
            $stats = PlatformDataImporter::importFromDir($root, $short, $this->db, $manifest);
            $this->warnings = array_merge($this->warnings, $stats['warnings']);
        } catch (\Throwable $e) {
            $this->warnings[] = '服务数据随包导入失败：' . $e->getMessage();
        }

        // 包声明式审批流程：resource/approvals/*.json 自动注册（安装/升级 upsert；失败仅告警不阻断）
        // 必须先于 adoptPackage：在途单据重新解析节点审批人时依赖新流程定义已入库
        if ($this->db !== null) {
            try {
                $seeded = (new ApprovalFlowSeeder(new ApprovalService(new PlatformStore($this->db))))->importPackage($short, $root);
                $this->warnings = array_merge($this->warnings, $seeded['warnings']);
            } catch (\Throwable $e) {
                $this->warnings[] = '审批流程声明加载失败：' . $e->getMessage();
            }
        }

        // 回收站接管：重装/升级同名包时清掉孤儿态，恢复对既有托管条目的正常读写（失败不阻断接入）
        if ($this->db !== null) {
            try {
                (new RecycleBinService(new PlatformStore($this->db)))->adoptPackage($short);
            } catch (\Throwable $e) {
                $this->warnings[] = '回收站接管失败：' . $e->getMessage();
            }
            // 审批单据接管：清孤儿态并按 flow 定义重新解析在途节点审批人快照
            try {
                (new ApprovalService(new PlatformStore($this->db)))->adoptPackage($short);
            } catch (\Throwable $e) {
                $this->warnings[] = '审批单据接管失败：' . $e->getMessage();
            }
            // 打印模板接管：重装/升级同名包时清掉孤儿态，恢复包级私有模板可用（失败不阻断接入）
            try {
                PrintTplService::instance(new PlatformStore($this->db))->adoptPackage($short);
            } catch (\Throwable $e) {
                $this->warnings[] = '打印模板接管失败：' . $e->getMessage();
            }
            // 任务定义与执行实例接管：重装/升级同名包时清孤儿态，恢复周期定义与历史实例（失败不阻断接入）
            try {
                (new SchedulerService(new PlatformStore($this->db)))->adoptPackage($short);
            } catch (\Throwable $e) {
                $this->warnings[] = '任务调度接管失败：' . $e->getMessage();
            }
        }
    }

    /** 按包定向清理其全部接入点，并清空指向该包的界面替换生效选择。 */
    public function remove(string $short): void
    {
        $data = $this->store->read();
        unset($data[$short]);
        // 清理该包曾作为生效选择的界面替换条目
        if (isset($data[IntegrationRegistry::GLOBAL]['active_ui']) && is_array($data[IntegrationRegistry::GLOBAL]['active_ui'])) {
            foreach ($data[IntegrationRegistry::GLOBAL]['active_ui'] as $target => $pkg) {
                if ($pkg === $short) {
                    unset($data[IntegrationRegistry::GLOBAL]['active_ui'][$target]);
                }
            }
        }
        $this->store->write($data);
    }

    /** 最近一次装配产生的警告（无效权限代码 / 无效 route 等）。 */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function permissions(): PermissionRegistry
    {
        return new PermissionRegistry($this->store);
    }

    public function menus(): MenuRegistry
    {
        return new MenuRegistry($this->store);
    }

    public function workspace(): WorkspaceRegistry
    {
        return new WorkspaceRegistry($this->store, $this->permChecker);
    }

    public function uiReplace(): UiReplaceRegistry
    {
        return new UiReplaceRegistry($this->store, $this->replaceablePages);
    }

    public function shortcuts(): ShortcutRegistry
    {
        return new ShortcutRegistry($this->store);
    }

    /** 读取声明文件（不存在返回空数组）。 */
    private function loadDecl(string $root, string $file): array
    {
        $path = $root . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $v = include $path;
        return is_array($v) ? $v : [];
    }

    /**
     * 供 PackageInstaller 的 integrate 槽位使用：返回 function(array $manifest): void。
     * 内部按 $manifest['short'] 从 $loadRoot/{short}/ 读取声明文件并登记。
     *
     * @param list<string> $replaceablePages 平台可替换页面清单
     * @param DbGateway|null $db 翻译种子导入网关（null 时跳过种子步骤）
     */
    public static function installHandler(string $loadRoot, string $registryFile, array $replaceablePages = [], ?DbGateway $db = null): callable
    {
        $loadRoot = rtrim($loadRoot, '/\\');
        return static function (array $manifest) use ($loadRoot, $registryFile, $replaceablePages, $db): void {
            (new self(new IntegrationRegistry($registryFile), $replaceablePages, null, null, $db))->install($loadRoot, $manifest);
        };
    }

    /** 覆盖式更新 handler：同 installHandler，供 Upgrader 的 integrate 槽位使用（翻译种子全量覆盖）。 */
    public static function upgradeHandler(string $loadRoot, string $registryFile, array $replaceablePages = [], ?DbGateway $db = null): callable
    {
        $loadRoot = rtrim($loadRoot, '/\\');
        return static function (array $manifest) use ($loadRoot, $registryFile, $replaceablePages, $db): void {
            (new self(new IntegrationRegistry($registryFile), $replaceablePages, null, null, $db))->upgrade($loadRoot, $manifest);
        };
    }

    /** 按包清理 handler：返回 function(string $short): void，供删除流程定向清理接入点。 */
    public static function removeHandler(string $registryFile): callable
    {
        return static function (string $short) use ($registryFile): void {
            (new self(new IntegrationRegistry($registryFile)))->remove($short);
        };
    }
}