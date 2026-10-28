<?php

declare(strict_types=1);

namespace linhub\host\support;

use linhub\capability\auth\Identity;
use linhub\capability\auth\UaisClient;
use linhub\host\integration\IntegrationRegistry;
use linhub\host\integration\MenuRegistry;
use linhub\host\integration\PermissionRegistry;
use linhub\host\integration\ShortcutRegistry;
use linhub\host\integration\UiReplaceRegistry;
use linhub\host\integration\WorkspaceRegistry;
use linhub\host\linservice\LinserviceStore;
use linhub\host\migration\PdoGateway;
use linhub\host\rbac\ArchiveBackup;
use linhub\host\rbac\AuditLog;
use linhub\host\rbac\AuthService;
use linhub\host\rbac\DbRbacStorage;
use linhub\host\rbac\RbacPermissionCalculator;
use linhub\host\rbac\RbacPermissionGroup;
use linhub\host\rbac\RbacRoles;
use linhub\host\rbac\RbacStorage;
use linhub\host\rbac\RbacUser;
use linhub\host\security\AuditTrail;
use linhub\host\services\ServiceRegistry;
use think\App;
use think\facade\Env;
use think\facade\Session;

/**
 * 宿主 GUI View 层装配助手（T7）
 *
 * 职责：
 *  - 统一接入点注册表文件（默认 code/host/data/integration/registry.json，目录不存在自动建）；
 *  - 首次初始化（注册文件为空）时自动登记一组演示 seed 数据（权限 / 菜单 / 工作台插槽），seed 只写一次；
 *  - 以进程级单例方式提供 AuthService，供跨请求恢复登录身份（session['linhub_identity']）；
 *  - 提供菜单可见性过滤、权限供体（super admin 全量，普通用户无 RBAC 数据给空集合）、工作台插槽过滤。
 *
 * 仅新增本类，不改动既有 AuthService / 各 Registry 内部逻辑。
 */
final class HostBootstrap
{
    /** 演示 seed 数据的短标识（注册表包键）。 */
    public const SEED_SHORT = 'host_demo';

    /** 平台管理（权限点/管理菜单）在注册表中的宿主包键（G0.3/G0.4）。 */
    public const ADMIN_SHORT = 'host_admin';

    /** 演示账号：直走 useMockIdentity 路径（真实 UAIS 账号未备好时用于演示）。 */
    public const DEMO_ADMIN = 'demo-admin';

    /**
     * 平台"可替换页面清单"（T4.7/L7，G7.1）：首批只列平台真实具备的默认页标识。
     * 供 UiReplaceRegistry 校验候选 target 合法性；目标页不在此清单则标记非法并回退默认页。
     */
    public const REPLACEABLE_PAGES = ['home/dashboard'];

    /** 进程内共享的 AuthService 单例。 */
    private static ?AuthService $auth = null;

    private App $app;
    private string $registryFile;

    /** 管理数据根目录（rbac/log 等持久业务数据区），默认 code/host/data，测试可注入隔离目录。 */
    private string $adminDataRoot;

    /** 管理域服务实例缓存（同一装配实例内共享，保证存储后端唯一）。 */
    private ?RbacStorage $rbacStorageSingleton = null;
    private ?RbacRoles $rolesSingleton = null;
    private ?RbacPermissionGroup $groupsSingleton = null;
    private ?RbacUser $usersSingleton = null;
    private ?RbacPermissionCalculator $calculatorSingleton = null;
    private ?AuditLog $auditLogSingleton = null;
    private ?AuditTrail $auditTrailSingleton = null;
    private ?ArchiveBackup $archiveBackupSingleton = null;
    private ?UiReplaceRegistry $uiReplaceSingleton = null;

    public function __construct(?App $app = null, ?string $registryFile = null, string $adminDataRoot = '')
    {
        $this->app = $app ?? App::getInstance();
        $this->registryFile = $registryFile ?? self::defaultRegistryFile();
        $this->adminDataRoot = $adminDataRoot !== ''
            ? rtrim(str_replace('\\', '/', $adminDataRoot), '/')
            : $this->defaultDataRoot();
    }

    /** 默认管理数据根目录：code/host/data（持久业务数据区，勿放 runtime 以免被 think clear 误清）。 */
    private function defaultDataRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4)) . '/data';
    }

    /** 管理数据根目录（G0.2 路径约定的唯一出口）。 */
    public function dataRoot(): string
    {
        return $this->adminDataRoot;
    }

    /** 全局设置数据文件：data/platform/setting.json。 */
    public function settingFile(): string
    {
        return $this->adminDataRoot . '/platform/setting.json';
    }

    /** RBAC 归档索引文件：data/rbac/archive-meta.json。 */
    public function rbacArchiveMetaFile(): string
    {
        return $this->adminDataRoot . '/rbac/archive-meta.json';
    }

    /** RBAC 归档快照目录：data/rbac/archives/。 */
    public function rbacArchiveDir(): string
    {
        return $this->adminDataRoot . '/rbac/archives';
    }

    /** 操作日志文件：data/log/audit.json。 */
    public function auditLogFile(): string
    {
        return $this->adminDataRoot . '/log/audit.json';
    }

    /** 管控审计轨迹文件：data/log/trail.json。 */
    public function auditTrailFile(): string
    {
        return $this->adminDataRoot . '/log/trail.json';
    }

    /** 默认注册表文件绝对路径：code/host/data/integration/registry.json。 */
    public static function defaultRegistryFile(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'data' . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'registry.json';
    }

    public function registryFile(): string
    {
        return $this->registryFile;
    }

    public function store(): IntegrationRegistry
    {
        return new IntegrationRegistry($this->registryFile);
    }

    public function permissions(): PermissionRegistry
    {
        return new PermissionRegistry($this->store());
    }

    public function menus(): MenuRegistry
    {
        return new MenuRegistry($this->store());
    }

    public function workspace(): WorkspaceRegistry
    {
        return new WorkspaceRegistry($this->store());
    }

    public function shortcuts(): ShortcutRegistry
    {
        return new ShortcutRegistry($this->store());
    }

    // ── G0.1/G0.2 管理域服务工厂：同一装配实例内单例，存储路径统一 ──

    /**
     * RBAC 存储（角色/权限组/用户及关联关系，DB 落库 S5，事务化写避免文件并发覆盖）。
     *
     * 默认连接系统库 lin_hub 的 lg_mgt_rbac_* 五表；DB 不可用时 ensure 抛异常，
     * 由调用方（控制器/同步流程）各自的 try/catch 决定降级策略。
     */
    public function rbacStorage(): RbacStorage
    {
        return $this->rbacStorageSingleton ??= new DbRbacStorage();
    }

    /** 角色管理服务。 */
    public function roles(): RbacRoles
    {
        return $this->rolesSingleton ??= new RbacRoles($this->rbacStorage());
    }

    /** 权限组管理服务。 */
    public function permissionGroups(): RbacPermissionGroup
    {
        return $this->groupsSingleton ??= new RbacPermissionGroup($this->rbacStorage());
    }

    /** 本地用户映射服务。 */
    public function rbacUsers(): RbacUser
    {
        return $this->usersSingleton ??= new RbacUser($this->rbacStorage());
    }

    /** 最终权限计算器。 */
    public function permissionCalculator(): RbacPermissionCalculator
    {
        return $this->calculatorSingleton ??= new RbacPermissionCalculator($this->rbacStorage());
    }

    /** 操作日志服务（data/log/audit.json）。 */
    public function auditLog(): AuditLog
    {
        return $this->auditLogSingleton ??= new AuditLog($this->auditLogFile());
    }

    /** 管控审计轨迹服务（data/log/trail.json）。 */
    public function auditTrail(): AuditTrail
    {
        return $this->auditTrailSingleton ??= new AuditTrail($this->auditTrailFile());
    }

    /** RBAC 数据归档备份服务（快照目录 data/rbac/archives/）。 */
    public function archiveBackup(): ArchiveBackup
    {
        return $this->archiveBackupSingleton ??= new ArchiveBackup(
            $this->rbacStorage(),
            $this->rbacArchiveMetaFile(),
            ['dir' => $this->rbacArchiveDir()],
        );
    }

    /** 界面替换登记与裁决服务（注入平台可替换页面清单，T4.6/G7.1）。 */
    public function uiReplace(): UiReplaceRegistry
    {
        return $this->uiReplaceSingleton ??= new UiReplaceRegistry(
            $this->store(),
            self::REPLACEABLE_PAGES,
        );
    }

    /** 进程级 AuthService 单例。 */
    public static function authService(): AuthService
    {
        if (self::$auth === null) {
            self::$auth = new AuthService();
        }
        return self::$auth;
    }

    /** 超级管理员 tag（同 UAIS provider 判定依据）。 */
    public function adminTag(): string
    {
        return (string) Env::get('UAIS_ADMIN_TAG', 'LinHub SuperAdministrator');
    }

    /**
     * 网关登录分流：演示账号走 useMockIdentity；其余走 loginWithPassword 真实 UAIS 路径。
     *
     * @return Identity|null 成功返回身份；失败返回 null，原因见 AuthService::lastError()
     */
    public function performLogin(string $username, string $password, ?AuthService $auth = null): ?Identity
    {
        $auth ??= self::authService();
        if ($username === self::DEMO_ADMIN) {
            $identity = new Identity(self::DEMO_ADMIN, '演示管理员', [$this->adminTag()]);
            $auth->useMockIdentity($identity);
            $this->recordLogin($identity->id);
            return $identity;
        }
        $identity = $auth->loginWithPassword($username, $password);
        if ($identity !== null) {
            // 真实 UAIS 身份：即时同步本地用户映射（首次自动建档 + 刷新登录时间），失败不阻断登录。
            $this->syncLocalUser($identity);
        }
        return $identity;
    }

    /**
     * UAIS 登录即时同步：把真实 UAIS 身份同步到本地 RBAC 用户映射（O-1 的登录态补偿）。
     *
     *  - 首次登录：按 ref_id=UAIS user id 自动建档（name 取昵称），授予默认「工作者」角色；
     *    绝不因 UAIS 身份（即便带超管 tag）自动授予本地超管或任何额外角色/权限组；
     *  - 已建档：仅在昵称变化时更新姓名，不覆盖本地已分配的角色与权限组（授权保持手动）；
     *  - 无论新建/已存在，均刷新最后登录时间。
     *
     * 同步失败不阻断登录流程（本地映射只是授权与展示的辅助视图）。
     */
    public function syncLocalUser(Identity $identity, ?string $at = null): void
    {
        $refId = (string) $identity->id;
        if ($refId === '') {
            return;
        }
        try {
            $users = $this->rbacUsers();
            $local = $users->get($refId) ?? $users->findByRefId($refId);

            if ($local === null) {
                $roles = $this->roles();
                $roles->ensureDefaultRoles();
                $uid = $users->create((string) $identity->name, $refId, ['role_worker']);
                $users->touchLogin($uid, $at);
                return;
            }

            $uid = (string) $local['id'];
            if ((string) ($local['name'] ?? '') !== (string) $identity->name && (string) $identity->name !== '') {
                $users->update($uid, (string) $identity->name);
            }
            $users->touchLogin($uid, $at);
        } catch (\Throwable $e) {
            // 同步失败不阻断登录
        }
    }

    /**
     * UAIS 手动全量同步：拉取 open/users 全量列表，按 UAIS id 与本地 ref_id 对齐。
     *
     * 与登录即时同步一致的最小授权原则：
     *  - 远端启用用户（status==1）本地缺失：自动建档并授默认「工作者」角色；
     *  - 已建档且昵称变化：仅更新姓名，不覆盖本地手动角色/权限组授权；
     *  - 禁用用户（status!=1）：已建档不改名、未建档不创建，计入 disabled；
     *  - 全量同步代表"管理员对齐名单"而非"用户登录"，故绝不刷新 last_login_at。
     *
     * 与 {@see syncLocalUser()} 不同，拉取或落库异常向上抛出，由控制器转 JSON 失败提示，
     * 便于超管明确知道同步未成功（而不是静默吞错）。
     *
     * @return array{total:int, created:int, updated:int, unchanged:int, disabled:int}
     * @throws \Throwable
     */
    /**
     * 同步 UAIS 用户到本地（支持三种策略，均为超管手动触发；不动本地角色/权限组授权）。
     *
     *  - diff_add（差异添加）：仅对本地缺失的启用账号建档；本地已存在账号完全不动（不覆盖任何字段）。
     *  - diff_overwrite（差异覆盖）：只同步 UAIS 返回的账号（缺失建档、已存在覆盖），
     *    系统单独添加、UAIS 未返回的本地账号不动；不删除任何本地记录。
     *  - overwrite（完全覆盖）：以 UAIS 为唯一权威全量对齐；UAIS 未返回的本地账号将被删除（removed），
     *    UAIS 禁用的账号本地同步为禁用。
     *
     * 三种模式都不覆盖本地已分配的角色 / 权限组授权，也不刷新登录时间。
     */
    public function syncRemoteUsers(string $mode = 'diff_overwrite', ?UaisClient $client = null): array
    {
        $mode = in_array($mode, ['diff_add', 'diff_overwrite', 'overwrite'], true) ? $mode : 'diff_overwrite';
        $client ??= new UaisClient();
        $rows    = $client->listUsers();

        $users = $this->rbacUsers();
        $this->roles()->ensureDefaultRoles();

        $stats = [
            'mode' => $mode,
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'disabled' => 0,
            'removed' => 0,
        ];

        // overwrite 模式需要判断"本地账号是否仍存在 UAIS"以便删除多余，先收集 UAIS 全部 refId
        $remoteRefs = [];
        foreach ($rows as $row) {
            $refId = (string) ($row['id'] ?? '');
            if ($refId !== '' && $refId !== '0') {
                $remoteRefs[$refId] = true;
            }
        }

        foreach ($rows as $row) {
            $refId = (string) ($row['id'] ?? '');
            if ($refId === '' || $refId === '0') {
                continue;
            }

            $enabled = (int) ($row['status'] ?? 0) === 1;
            $name    = trim((string) ($row['nickname'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['username'] ?? ''));
            }

            $local   = $users->findByRefId($refId);
            $profile = $this->extractUaisProfile($row);

            // 禁用账号：不新建（避免产生"禁用空壳"）；已存在时仅在覆盖类模式同步为禁用
            if (!$enabled) {
                if ($local !== null && $mode !== 'diff_add') {
                    if ((string) ($local['status'] ?? '1') !== '0') {
                        $users->patchProfile((string) $local['id'], ['status' => 0]);
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }
                } else {
                    $stats['disabled']++;
                }
                continue;
            }

            // 新建：本地缺失且启用
            if ($local === null) {
                if ($name === '') {
                    continue;
                }
                $users->create($name, $refId, ['role_worker'], [], null, $profile);
                $stats['created']++;
                continue;
            }

            // 已存在：差异添加不动；覆盖类模式改名 + 覆盖 UAIS 同步字段
            if ($mode === 'diff_add') {
                $stats['unchanged']++;
                continue;
            }

            $changed = false;
            if ($name !== '' && (string) ($local['name'] ?? '') !== $name) {
                $users->update((string) $local['id'], $name);
                $changed = true;
            }
            if ($profile !== []) {
                $users->patchProfile((string) $local['id'], $profile);
                $changed = true;
            }
            $stats[$changed ? 'updated' : 'unchanged']++;
        }

        // 完全覆盖：删除本地存在但 UAIS 已不再返回的账号（纯粹多出的本地记录被清理）
        if ($mode === 'overwrite') {
            foreach ($users->all() as $u) {
                $ref = (string) ($u['ref_id'] ?? '');
                if ($ref === '' || isset($remoteRefs[$ref])) {
                    continue;
                }
                $users->delete((string) ($u['id'] ?? ''));
                $stats['removed']++;
            }
        }

        return $stats;
    }

    /**
     * 从 UAIS 用户行抽取同步字段（不覆盖本地 remark 等可编辑字段）。
     *
     * @param array $row UAIS listUsers 单条记录
     * @return array
     */
    private function extractUaisProfile(array $row): array
    {
        $out = [];
        $map = [
            'username'   => 'username',
            'email'      => 'email',
            'phone'      => 'phone',
            'avatar'     => 'avatar',
            'gender'     => 'gender',
            'department' => 'department',
            'company'    => 'company',
            'title'      => 'title',
            'user_type'  => 'user_type',
            'status'     => 'status',
        ];
        foreach ($map as $remoteKey => $localKey) {
            if (array_key_exists($remoteKey, $row)) {
                $out[$localKey] = $row[$remoteKey];
            }
        }
        // tags 在 UAIS 里通常是数组；保持原样合并
        if (isset($row['tags']) && is_array($row['tags'])) {
            $out['tags'] = array_values(array_map('strval', $row['tags']));
        }
        return $out;
    }

    /**
     * 记录某身份本次登录时间到本地用户映射。
     *
     * 本地无该用户映射（未在用户管理建档）时静默跳过；先按内部 id 命中（mock 身份），
     * 再按 ref_id 命中（真实 UAIS 身份 id 即外部标识）。时间戳由 RbacUser::touchLogin 落库，
     * 失败不阻断登录流程。
     */
    public function recordLogin(string $identityId, ?string $at = null): void
    {
        if ($identityId === '') {
            return;
        }
        try {
            $users = $this->rbacUsers();
            $local = $users->get($identityId) ?? $users->findByRefId($identityId);
            if ($local !== null) {
                $users->touchLogin((string) $local['id'], $at);
            }
        } catch (\Throwable $e) {
            // 登录时间记录失败不阻断登录
        }
    }

    /** 当前请求身份：内存态优先，否则从 session 恢复；均无返回 null（视为未登录）。 */
    public function currentUser(?AuthService $auth = null): ?Identity
    {
        $auth ??= self::authService();
        $identity = $auth->currentIdentity();
        if ($identity !== null) {
            return $identity;
        }
        $saved = $this->readSessionIdentity();
        if (is_array($saved) && isset($saved['id']) && $saved['id'] !== '') {
            $identity = new Identity(
                (string) $saved['id'],
                (string) ($saved['name'] ?? ''),
                array_values(array_map('strval', (array) ($saved['tags'] ?? []))),
            );
            $auth->useMockIdentity($identity);
            return $identity;
        }
        return null;
    }

    /** 登录成功后写入 PHP 原生 session，实现跨请求登录态。 */
    public function saveLoginToSession(Identity $identity): void
    {
        Session::set('linhub_identity', [
            'id'   => $identity->id,
            'name' => $identity->name,
            'tags' => array_values($identity->tags),
        ]);
    }

    /** 清除登录 session。 */
    public function clearLoginSession(): void
    {
        try {
            Session::delete('linhub_identity');
        } catch (\Throwable $e) {
            // CLI/无会话环境下静默
        }
    }

    /**
     * 用户最终权限供体：super admin 视为拥有全部权限；否则因尚无 RBAC 角色映射给空集合。
     *
     * @return list<string>
     */
    public function userPerms(Identity $identity, ?AuthService $auth = null): array
    {
        $auth ??= self::authService();
        if ($auth->isSuperAdmin($identity)) {
            return array_values(array_map(
                static fn ($p): string => (string) ($p['code'] ?? ''),
                $this->permissions()->all(),
            ));
        }
        return [];
    }

    /**
     * 角色类型 → 独立 home 页路径的固定映射（按角色类型归属，同类型角色共享一个 home）。
     *
     * @var array<string, string>
     */
    public const ROLE_HOME_PATHS = [
        RbacRoles::WORKER           => '/admin/home/worker',
        RbacRoles::FUNCTIONAL_ADMIN => '/admin/home/functionalAdmin',
        RbacRoles::SYSTEM_ADMIN     => '/admin/home/systemAdmin',
        RbacRoles::SUPER_ADMIN      => '/admin/home/superAdmin',
    ];

    /**
     * 多角色时的落地优先级（数字小者优先）：超管 > 系统管理员 > 功能管理员 > 工作者。
     *
     * @var array<string, int>
     */
    private const ROLE_PRIORITY = [
        RbacRoles::SUPER_ADMIN      => 1,
        RbacRoles::SYSTEM_ADMIN     => 2,
        RbacRoles::FUNCTIONAL_ADMIN => 3,
        RbacRoles::WORKER           => 4,
    ];

    /** 兜底 home：无角色 / 未匹配本地用户时使用（工作者首页）。 */
    public const DEFAULT_HOME_PATH = '/admin/home/worker';

    /**
     * 解析某身份拥有的全部角色类型。
     *
     * 超管身份（UAIS admin tag）直接含 SUPER_ADMIN；再反查本地 RbacUser
     * （先按内部 id 匹配 mock 身份，再按 ref_id 匹配外部身份）聚合其角色类型，去重。
     *
     * @return list<string>
     */
    public function roleTypesFor(Identity $identity, ?AuthService $auth = null): array
    {
        $auth ??= self::authService();
        $types = [];
        if ($auth->isSuperAdmin($identity)) {
            $types[] = RbacRoles::SUPER_ADMIN;
        }

        $users = $this->rbacUsers();
        $local = $users->get($identity->id) ?? $users->findByRefId($identity->id);
        if ($local !== null) {
            $roles = $this->roles();
            $roles->ensureDefaultRoles();
            foreach ($users->rolesOf((string) $local['id']) as $roleId) {
                $role = $roles->get((string) $roleId);
                $type = $role === null ? '' : (string) ($role['type'] ?? '');
                if ($type !== '' && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        return $types;
    }

    /**
     * 某身份的主角色类型：多角色按 {@see ROLE_PRIORITY} 取最高优先级；无角色兜底 WORKER。
     */
    public function primaryRoleType(Identity $identity, ?AuthService $auth = null): string
    {
        $types = $this->roleTypesFor($identity, $auth);
        if ($types === []) {
            return RbacRoles::WORKER;
        }
        usort(
            $types,
            static fn (string $a, string $b): int => (self::ROLE_PRIORITY[$a] ?? 99) <=> (self::ROLE_PRIORITY[$b] ?? 99),
        );
        return $types[0];
    }

    /** 某身份登录后应落地的独立 home 页路径（按主角色类型解析，未匹配走兜底）。 */
    public function homePathFor(Identity $identity, ?AuthService $auth = null): string
    {
        $type = $this->primaryRoleType($identity, $auth);
        return self::ROLE_HOME_PATHS[$type] ?? self::DEFAULT_HOME_PATH;
    }

    /**
     * 某身份可见的菜单树（权限供体 → 权限过滤 → 父子树），供管理页侧边栏统一消费（G0.6）。
     *
     * @return list<array{item: array<string,mixed>, children: array}>
     */
    public function menuTreeFor(Identity $identity, ?AuthService $auth = null): array
    {
        $userPerms = $this->userPerms($identity, $auth);
        $menus     = $this->visibleMenus($this->menus()->all(), $userPerms);
        return $this->buildMenuTree($menus);
    }

    /**
     * 按用户权限过滤可见菜单（perms 为空 => 恒可见；否则任一命中即可见）。
     *
     * @param list<array<string, mixed>> $menus
     * @param list<string>               $userPerms
     * @return list<array<string, mixed>>
     */
    public function visibleMenus(array $menus, array $userPerms): array
    {
        return array_values(array_filter(
            $menus,
            static function (array $m) use ($userPerms): bool {
                $perms = (array) ($m['perms'] ?? []);
                if ($perms === []) {
                    return true;
                }
                return count(array_intersect($perms, $userPerms)) > 0;
            },
        ));
    }

    /**
     * 由扁平菜单构建父子树（供侧边栏递归渲染）。
     *
     * @param list<array<string, mixed>> $menus
     * @return list<array{item: array<string,mixed>, children: array}>
     */
    public function buildMenuTree(array $menus): array
    {
        $nodes = [];
        foreach ($menus as $m) {
            $nodes[(string) ($m['id'] ?? '')] = ['item' => $m, 'children' => []];
        }
        $roots = [];
        foreach ($nodes as $id => &$node) {
            $pid = $node['item']['parent'] ?? null;
            if ($pid !== null && isset($nodes[$pid])) {
                $nodes[$pid]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    }

    /**
     * 首次初始化：当注册文件为空时登记演示 seed（权限 / 菜单 / 工作台插槽）。
     * 无论是否首次，随后都幂等补齐平台权限点与平台管理菜单（G0.3/G0.4）：
     * 老环境注册表已非空时同样生效，且重复调用内容不变即零写入。
     */
    public function ensureSeeded(): void
    {
        $store = $this->store();
        if ($store->read() === []) {
            (new PermissionRegistry($store))->register(self::SEED_SHORT, $this->seedPermissions());
            (new MenuRegistry($store))->register(
                self::SEED_SHORT,
                $this->seedMenus(),
                fn (string $route): bool => $this->routeExists($route),
            );
            (new WorkspaceRegistry($store))->registerSlots(self::SEED_SHORT, $this->seedSlots());
        }
        $this->ensureAdminPermissions();
        $this->ensureAdminMenus();
        $this->ensureAdminShortcuts();
        $this->ensureLinserviceSync();
    }

    /**
     * 平台服务与能力落库同步（lg_mgt_ 前缀，系统库 lin_hub）。
     *
     * 启动时先 registerPackageProvides() 把 package 源注入内存，再走 persistServices 全量落库。
     *
     * 以内存/JSON 为事实源做全量镜像，持久化到 lg_mgt_service / lg_mgt_capability：
     *  - 服务：取 ServiceRegistry::all()（不含内存时先注入宿主服务）；
     *  - 能力：取 IntegrationRegistry::read() 扁平化；
     *  - 水合：从服务表回填缺失的 package 源服务（重启后内存注册表被清空，仅保留 host 源），幂等。
     * 落库失败不阻断平台交互（仅持久化镜像），异常静默降级。
     */
    private function ensureLinserviceSync(): void
    {
        try {
            ServiceRegistry::bootstrap();
            $this->registerPackageProvides();
            $store = $this->linserviceStore();
            $store->persistServices(ServiceRegistry::all());
            $store->persistCapabilities($this->store()->read());
            foreach ($store->servicesAll() as $id => $entry) {
                if ($entry['source'] === 'package' && ServiceRegistry::get($id) === null) {
                    ServiceRegistry::registerPackage($id, $entry['class'], $entry['version']);
                }
            }
        } catch (\Throwable $e) {
            // 仅持久化镜像，DB 不可用或落库失败不阻断平台正常交互。
        }
    }

    /** 扫描已启用功能包 manifest.provides，注册为 package 源服务（内存态）。 */
    private function registerPackageProvides(): void
    {
        foreach (ServiceRegistry::packageProvides() as $id => $entry) {
            if (ServiceRegistry::get($id) !== null) {
                continue;
            }
            ServiceRegistry::registerPackage($id, (string) ($entry['class'] ?? ''), (string) ($entry['version'] ?? ''));
        }
    }

    /** 建立连接系统库 lin_hub 的 PdoGateway（按 config/database.php 的 env），用于 linservice 落库。 */
    private function linserviceStore(): LinserviceStore
    {
        $driver  = (string) Env::get('DB_TYPE', 'mysql');
        $host    = (string) Env::get('DB_HOST', '127.0.0.1');
        $port    = (string) Env::get('DB_PORT', '3306');
        $db      = (string) Env::get('DB_NAME', '');
        $user    = (string) Env::get('DB_USER', 'root');
        $pass    = (string) Env::get('DB_PASS', '');
        $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');
        $dsn     = "{$driver}:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo     = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        return new LinserviceStore(new PdoGateway($pdo));
    }

    /**
     * G0.3 平台权限点幂等登记（host_admin 包键）。
     *
     * 平台权限点清单为全量定义：与注册表现状逐点比对，完全一致则跳过写入；
     * 任一缺失或内容变化才整体重写该包键的 permissions 分区，保证任意次调用恰好一份。
     */
    public function ensureAdminPermissions(): void
    {
        $store    = $this->store();
        $defs     = $this->adminPermissionDefs();
        $existing = (array) (($store->get(self::ADMIN_SHORT) ?? [])['permissions'] ?? []);
        if ($this->samePermissionDefs($existing, $defs)) {
            return;
        }
        (new PermissionRegistry($store))->register(self::ADMIN_SHORT, $defs);
    }

    /**
     * G0.4 平台管理菜单幂等登记（host_admin 包键）。
     *
     * 菜单为全量定义并逐条做真实路由校验：控制器/动作已存在的菜单 valid=true 注入导航，
     * 尚未实现的（如 G1 之后的角色页）valid=false 仅登记不显示；后续阶段控制器落地后，
     * 下次调用自动转有效，无需数据迁移。内容无变化时零写入。
     * 同时把老环境 host_demo 中被平台管理组取代的 menu.admin* 旧菜单幂等摘除。
     */
    public function ensureAdminMenus(): void
    {
        $store = $this->store();
        $defs  = $this->adminMenuDefs();

        $expected = [];
        foreach ($defs as $d) {
            $route    = (string) ($d['route'] ?? '');
            $expected[] = [
                'id'     => (string) ($d['id'] ?? ''),
                'parent' => $d['parent'] ?? null,
                'title'  => (string) ($d['title'] ?? ''),
                'route'  => $route,
                'icon'   => (string) ($d['icon'] ?? ''),
                'order'  => (int) ($d['order'] ?? 0),
                'perms'  => array_values((array) ($d['perms'] ?? [])),
                'valid'  => $route === '' || $this->routeExists($route),
                'short'  => self::ADMIN_SHORT,
            ];
        }

        $data     = $store->read();
        $existing = (array) ($data[self::ADMIN_SHORT]['menus'] ?? []);

        // 老环境迁移：host_demo 中历史菜单收口到 host_admin——
        // 旧 IA：menu.admin / menu.admin.plugin；分导台 IA：menu.system.package / menu.superadmin（父组移交）；
        // 分导台根组（workspace/system/dev）已由 adminMenuDefs 并入 host_admin 登记，host_demo 不再持有
        $demoMenus = (array) ($data[self::SEED_SHORT]['menus'] ?? []);
        if ($demoMenus !== []) {
            $retired = ['menu.admin', 'menu.admin.plugin', 'menu.system.package', 'menu.superadmin',
                        'menu.workspace', 'menu.system', 'menu.dev'];
            $filtered = array_values(array_filter(
                $demoMenus,
                static fn ($m): bool => !in_array((string) ($m['id'] ?? ''), $retired, true),
            ));
            if (count($filtered) !== count($demoMenus)) {
                $store->save(self::SEED_SHORT, ['menus' => $filtered]);
            }
        }

        if (!$this->sameMenuItems($existing, $expected)) {
            (new MenuRegistry($store))->register(
                self::ADMIN_SHORT,
                $defs,
                fn (string $route): bool => $this->routeExists($route),
            );
        }
    }

    /**
     * 平台内置快捷入口幂等登记（host_admin 包键，shortcuts 分区）。
     *
     * 声明收敛到包根 shortcuts.php（default ≤3 / optional ≤20）；与注册表现状逐项比对，
     * 内容无变化时零写入。候选池由各包声明汇总，不经此处不影响已安装功能包的声明。
     */
    public function ensureAdminShortcuts(): void
    {
        $store    = $this->store();
        $defs     = $this->adminShortcutDefs();
        $existing = (new ShortcutRegistry($store))->get(self::ADMIN_SHORT);
        if ($this->sameShortcutDefs($existing, $defs)) {
            return;
        }
        (new ShortcutRegistry($store))->register(self::ADMIN_SHORT, $defs);
    }

    /** 平台内置快捷入口全量清单（与功能包 shortcuts.php 同构，经 include 返回）。 */
    private function adminShortcutDefs(): array
    {
        $file = dirname(__DIR__, 2) . '/shortcuts.php';
        $defs = is_file($file) ? include $file : [];
        return is_array($defs) ? $defs : [];
    }

    /** 比较已登记快捷入口与全量定义是否一致。 */
    private function sameShortcutDefs(array $existing, array $defs): bool
    {
        $norm = static fn (array $v): string => (string) json_encode([
            array_values((array) ($v['default'] ?? [])),
            array_values((array) ($v['optional'] ?? [])),
        ], JSON_UNESCAPED_UNICODE);
        return $norm($existing) === $norm($defs);
    }

    /** 比较已登记权限点与全量定义是否一致（忽略注册时追加的 short 字段）。 */
    private function samePermissionDefs(array $existing, array $defs): bool
    {
        if (count($existing) !== count($defs)) {
            return false;
        }
        $norm = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $roles = array_values((array) ($row['roles'] ?? []));
                sort($roles);
                $out[(string) ($row['code'] ?? '')] = implode('|', [
                    (string) ($row['title'] ?? ''),
                    (string) ($row['description'] ?? ''),
                    (string) ($row['group'] ?? ''),
                    implode(',', $roles),
                ]);
            }
            ksort($out);
            return $out;
        };
        return $norm($existing) === $norm($defs);
    }

    /** 比较已登记菜单与期望菜单是否一致（忽略 warn 等派生字段）。 */
    private function sameMenuItems(array $existing, array $expected): bool
    {
        $norm = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $perms = array_values((array) ($row['perms'] ?? []));
                sort($perms);
                $out[(string) ($row['id'] ?? '')] = json_encode([
                    'parent' => $row['parent'] ?? null,
                    'title'  => (string) ($row['title'] ?? ''),
                    'route'  => (string) ($row['route'] ?? ''),
                    'icon'   => (string) ($row['icon'] ?? ''),
                    'order'  => (int) ($row['order'] ?? 0),
                    'perms'  => $perms,
                    'valid'  => !empty($row['valid']),
                ], JSON_UNESCAPED_UNICODE);
            }
            ksort($out);
            return $out;
        };
        return $norm($existing) === $norm($expected);
    }

    /** G0.3 平台权限点全量清单（24 个，编码遵循 J8 产品:资源:操作；roles 空=不限角色 J7-b）。 */
    private function adminPermissionDefs(): array
    {
        // 与功能包 permissions.php 同构：权限点声明收敛到包根 permissions.php（`return [...]`，A4/J7/J8 约定），
        // 经 include 返回，保证平台权限与功能包权限以完全一致的方式声明与维护。
        $file = dirname(__DIR__, 2) . '/permissions.php';
        $defs = is_file($file) ? include $file : [];
        return is_array($defs) ? $defs : [];
    }

    /**
     * G0.4 平台菜单全量清单（host_admin）。
     *
     * 结构 = 分导台根组（workspaces.php 的 menus 分区）+ 平台菜单子项（menus.php，parent 指向分导台 id）。
     * 与功能包 menus.php 同构（`return [...]`，A5/J6 约定），经 include 返回，统一在 ensureAdminMenus 幂等登记。
     * 父组始终可见；子项路由对应的控制器在 G1–G8 陆续落地，落地前 valid=false 不显示。
     * 子项 perms 挂平台权限点（view），超管恒全量；未来普通用户即便有菜单数据也被控制器超管门禁拦截（C2）。
     */
    private function adminMenuDefs(): array
    {
        // 分导台根组：workspaces.php['menus']（工作区/系统功能/超管功能/开发辅助）
        $roots = (array) ($this->seedWorkspaceFile()['menus'] ?? []);
        // 平台菜单子项：menus.php（parent 指向分导台 id）
        $file  = dirname(__DIR__, 2) . '/menus.php';
        $items = is_file($file) ? include $file : [];
        return array_merge($roots, is_array($items) ? $items : []);
    }

    /**
     * 判定 route（控制器/方法）是否真实存在；空 route 视为有效。
     *
     * 支持多应用两种写法：
     *  - 绝对应用路径 /{app}/{Controller}/{method}，如 /admin/package/index
     *  - 相对 {Controller}/{method}（默认应用 index），如 home/dashboard
     */
    public function routeExists(string $route): bool
    {
        if ($route === '') {
            return true;
        }
        $route      = ltrim($route, '/');
        $parts      = explode('/', $route);
        $method     = (string) array_pop($parts);
        $controller = implode('\\', array_filter($parts));
        if ($controller === '' || $method === '') {
            return false;
        }
        $segments = explode('\\', $controller);
        if (count($segments) >= 2) {
            $appName = (string) array_shift($segments);
        } else {
            $appName = ($this->app->http->getName() ?: 'index');
        }
        // 与 ThinkPHP 运行时 Loader::parseName($name, 1) 保持一致：URL 段 snake_case 转 StudlyCase，
        // 如 package_review → PackageReview（已是 PascalCase 的段不受影响）
        $ctrlSegments = array_map(
            static fn ($seg) => str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $seg))),
            $segments
        );
        $class = 'app\\' . $appName . '\\controller\\' . implode('\\', $ctrlSegments);
        return class_exists($class) && method_exists($class, $method);
    }

    /** 从 session 读取登录身份；读取失败返回 null（不抛出）。 */
    private function readSessionIdentity(): ?array
    {
        try {
            $v = Session::get('linhub_identity');
            return is_array($v) ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> 演示权限点（含指定角色与不限角色两种）。 */
    private function seedPermissions(): array
    {
        return [
            ['code' => 'host:demo:view',   'title' => '查看演示数据', 'description' => '允许浏览演示数据列表与详情。', 'group' => 'host',   'roles' => ['演示管理员']],
            ['code' => 'host:demo:manage', 'title' => '管理演示数据', 'description' => '允许新增、编辑、删除演示数据。', 'group' => 'host',   'roles' => ['演示管理员']],
            ['code' => 'host:system:config', 'title' => '系统配置',   'description' => '允许查看与维护平台系统配置。', 'group' => 'system', 'roles' => []],
            ['code' => 'host:system:package', 'title' => '功能包管理', 'description' => '允许浏览、安装、升级与卸载功能包。', 'group' => 'system', 'roles' => []],
        ];
    }

    /** @return list<array<string, mixed>> 预设分导台菜单。 */
    private function seedMenus(): array
    {
        // 分导台根组已收口至 host_admin（adminMenuDefs 并入 workspaces.php 的 menus 分区），
        // host_demo 不再 seed 分导台菜单，避免与 host_admin 重复登记。
        return [];
    }

    /** @return list<array<string, mixed>> 演示工作台插槽（至少 1 个带 perms、1 个无 perms）。 */
    private function seedSlots(): array
    {
        // 与功能包 workspace.php 同构：演示插槽声明收敛到包根 workspaces.php（slots 分区）。
        return (array) ($this->seedWorkspaceFile()['slots'] ?? []);
    }

    /** include 包根 workspaces.php（缺失/非数组兜底空结构）。 */
    private function seedWorkspaceFile(): array
    {
        $file = dirname(__DIR__, 2) . '/workspaces.php';
        $defs = is_file($file) ? include $file : [];
        return is_array($defs) ? $defs : [];
    }
}