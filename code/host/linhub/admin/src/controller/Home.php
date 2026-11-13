<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\home\HomeCustomization;
use linhub\host\home\UserProfile;
use linhub\host\lifecycle\PlatformUpdater;
use linhub\host\rbac\RbacRoles;
use linhub\host\services\attachment\AttachmentService;
use linhub\host\services\approval\ApprovalService;
use linhub\host\services\approval\ApprovalSettings;
use linhub\host\services\dictionary\DictionaryService;
use linhub\host\services\inbox\InboxService;
use linhub\host\services\setting\SiteSettingService;
use linhub\host\support\HostBootstrap;

/**
 * 宿主工作台（T7.2）—— admin 应用（中后台，登录校验由应用中间件统一处理）
 *
 *  - GET /admin/home           首页 = 工作台
 *  - GET /admin/home/dashboard 工作台主框架：左侧可见菜单（父子树） + 主区可见插槽卡片列表
 */
class Home extends AdminController
{
    /** 首页即工作台。 */
    public function index()
    {
        return $this->dashboard();
    }

    /** 工作台主框架：按用户权限渲染可见菜单与插槽。普通用户与超管均可进入（非超管门禁作用域）。 */
    public function dashboard()
    {
        $identity = $this->identity();
        if ($identity === null) {
            return redirect('/admin/auth/login');
        }

        $userPerms = $this->bootstrap->userPerms($identity, HostBootstrap::authService());
        $tree      = $this->bootstrap->menuTreeFor($identity);
        // 插槽按权限 + 角色类型双重过滤（角色白名单命中才可见）
        $slots     = $this->bootstrap->workspace()->slotsAllowedBy(
            $userPerms,
            $this->bootstrap->roleTypesFor($identity),
        );

        $isSuperAdmin = HostBootstrap::authService()->isSuperAdmin($identity);

        // 审批通知入口开关（fail-soft：字典服务异常时兜底开启，避免误隐藏入口）
        $approvalNotifyEnabled = true;
        try {
            $approvalNotifyEnabled = (new ApprovalSettings())->notifyEnabled();
        } catch (\Throwable) {
            $approvalNotifyEnabled = true;
        }

        // 外观与引导：允许换肤时用户私人皮肤优先于平台默认，否则统一用平台默认皮肤
        // （用户显式保存过 'default' 与未设置等价——均回退平台默认皮肤；
        //   收窄前遗留的清单外皮肤（business/tech 等）同样回退平台默认）
        $siteSettings  = new SiteSettingService();
        $skinAllowed   = $siteSettings->skinChangeAllowed();
        $userSkin      = (string) ($this->userProfile()->getSkin((string) $identity->id) ?? '');
        $effectiveSkin = ($skinAllowed && $userSkin !== '' && $userSkin !== 'default' && SiteSettingService::isSkinAllowed($userSkin))
            ? $userSkin : $siteSettings->defaultSkin();

        // wdState：供前端 workdesktop-ai（WdStation）直接消费的 JSON 数据（分组菜单/用户/插槽）
        $wdState = $this->adminWdState($identity, [
            'roleHomePath' => $this->bootstrap->homePathFor($identity, HostBootstrap::authService()),
            // 超管可在首页 tab 下拉切换查看各角色工作台
            'isSuperAdmin' => $isSuperAdmin,
            'roleHomes'    => $isSuperAdmin ? $this->roleHomeOptions() : [],
            // 工作台皮肤：用户私人配置优先，未设置或换肤关闭时用平台默认皮肤
            'skin' => $effectiveSkin,
            // 皮肤切换清单白名单（前端色板按此过滤，与全局设置默认皮肤下拉同源）
            'allowedSkins' => SiteSettingService::ALLOWED_SKINS,
            // 右上角皮肤切换入口是否显示（全局设置「允许用户更换皮肤」，默认允许）
            'skinAllowed' => $skinAllowed,
            // 右上角「系统指导」按钮是否显示（全局设置，默认显示）
            'guideEnabled' => $siteSettings->guideEnabled(),
            // 右上角审批通知入口是否显示（服务设置「开启审批通知」，默认开启）
            'approvalNotifyEnabled' => $approvalNotifyEnabled,
            'slots' => array_map(static fn (array $s): array => [
                'title'  => (string) ($s['title'] ?? ''),
                'render' => (string) ($s['render'] ?? ''),
            ], $slots),
        ]);

        return $this->app->view->fetch('home/dashboard', [
            'identity'     => $identity,
            'userPerms'    => $userPerms,
            'menuTree'     => $tree,
            'slots'        => $slots,
            'wdState'      => $wdState,
            // workdesktop-ai 安装记录版本（data/platform-versions.json）：上游 dist 内烘焙的版本常量可能滞后，显示以后端安装记录为准
            'wdLibVersion' => (new PlatformUpdater())->workdesktopVersion(),
        ]);
    }

    /** 工作者首页。 */
    public function worker()
    {
        return $this->renderRoleHome('worker', '工作者');
    }

    /** 功能管理员首页。 */
    public function functionalAdmin()
    {
        return $this->renderRoleHome('functional_admin', '功能管理员');
    }

    /** 系统管理员首页。 */
    public function systemAdmin()
    {
        return $this->renderRoleHome('system_admin', '系统管理员');
    }

    /** 超级管理员首页（支持个人定制）。 */
    public function superAdmin()
    {
        return $this->renderRoleHome('super_admin', '超级管理员');
    }

    /**
     * 超管可切换的角色工作台列表（按角色优先级排序：超管 → 系统管理员 → 功能管理员 → 审核员 → 工作者）。
     * 仅供首页 tab 下拉菜单消费，key 为预设角色英文键小写标识，path 为对应独立首页路径。
     *
     * @return list<array{key:string,label:string,path:string}>
     */
    private function roleHomeOptions(): array
    {
        // 审核员复用工作者首页（ROLE_HOME_PATHS 同路径），不单独出 tab
        $names = [
            RbacRoles::WORKER           => '工作者',
            RbacRoles::FUNCTIONAL_ADMIN => '功能管理员',
            RbacRoles::SYSTEM_ADMIN     => '系统管理员',
            RbacRoles::SUPER_ADMIN      => '超级管理员',
        ];
        $out = [];
        foreach ([
            RbacRoles::SUPER_ADMIN,
            RbacRoles::SYSTEM_ADMIN,
            RbacRoles::FUNCTIONAL_ADMIN,
            RbacRoles::WORKER,
        ] as $type) {
            $path = (string) (HostBootstrap::ROLE_HOME_PATHS[$type] ?? '');
            if ($path === '') {
                continue;
            }
            $out[] = [
                'key'   => strtolower($type),
                'label' => ($names[$type] ?? $type) . '工作台',
                'path'  => $path,
            ];
        }
        return $out;
    }

    /**
     * 超管首页统计面板（只读）：用户 / 使用情况 / 服务使用 三块聚合。
     * GET /admin/home/stats
     */
    public function stats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }

        // 服务使用块：复用平台公共服务各自现成的 stats()
        $serviceStats = [
            'attachment' => (new AttachmentService())->stats(),
            'dictionary' => (new DictionaryService())->stats(),
            'inbox'      => (new InboxService())->stats(),
        ];

        $payload = self::homeStatsPayload(
            $this->bootstrap->rbacUsers()->all(),
            $this->bootstrap->roles()->all(),
            $this->bootstrap->permissionGroups()->all(),
            $this->bootstrap->permissions()->all(),
            $this->bootstrap->auditTrail()->all(),
            $serviceStats,
        );

        // 平台信息块（只读展示）：运行环境摘要
        $payload['platform'] = [
            'php'  => PHP_VERSION,
            'os'   => PHP_OS_FAMILY,
            'time' => date('Y-m-d H:i'),
        ];

        return $this->jsonOk($payload);
    }

    /**
     * 保存首页个人定制。入参 homeKey、menuIds（有序 id 列表）；reset=1 时清除定制。
     * 仅接受当前用户可见菜单范围内的 id，越权 id 一律剔除。
     */
    public function customSave()
    {
        $identity = $this->identity();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }

        $homeKey = (string) $this->request->param('homeKey', '');
        if (!in_array($homeKey, self::CUSTOMIZABLE_HOMES, true)) {
            return $this->jsonFail('非法的首页标识', 422);
        }

        // 可见范围白名单：仅限各包 shortcuts 声明且当前用户可见的卡片
        $userPerms = $this->bootstrap->userPerms($identity, HostBootstrap::authService());
        $visible   = $this->bootstrap->visibleMenus($this->bootstrap->menus()->all(), $userPerms);
        $allowed   = [];
        foreach ($this->homeShortcutOptions($visible)['all'] as $opt) {
            $allowed[(string) $opt['id']] = true;
        }

        $customization = $this->homeCustomization();

        if ((int) $this->request->param('reset', 0) === 1) {
            $customization->reset((string) $identity->id, $homeKey);
            return $this->jsonOk(['reset' => true]);
        }

        $rawIds  = (array) $this->request->param('menuIds', []);
        $menuIds = [];
        foreach ($rawIds as $id) {
            $id = (string) $id;
            if (isset($allowed[$id]) && !in_array($id, $menuIds, true)) {
                $menuIds[] = $id;
            }
        }

        $customization->save((string) $identity->id, $homeKey, $menuIds);
        return $this->jsonOk(['menuIds' => $menuIds]);
    }

    /** 可定制的角色首页白名单（homeKey 与视图名一致）。 */
    private const CUSTOMIZABLE_HOMES = ['super_admin', 'functional_admin', 'system_admin', 'worker'];

    /**
     * 保存当前用户个人资料（显示名称）。入参 name；按用户持久化并同步 session。
     */
    public function profileSave()
    {
        $identity = $this->identity();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }

        $name = (string) $this->request->param('name', '');
        if (trim($name) === '') {
            return $this->jsonFail('显示名称不能为空', 422);
        }

        $this->userProfile()->saveName((string) $identity->id, $name);

        // 同步内存身份与 session，使刷新后立即生效
        $updated = new Identity($identity->id, trim($name), $identity->tags);
        HostBootstrap::authService()->useMockIdentity($updated);
        $this->bootstrap->saveLoginToSession($updated);

        return $this->jsonOk(['name' => $updated->name]);
    }

    /**
     * 保存当前用户工作台皮肤。入参 skin（空串恢复默认）；保存到用户私人配置（profile.json）。
     */
    public function skinSave()
    {
        $identity = $this->identity();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }

        $skin = trim((string) $this->request->param('skin', ''));
        if ($skin !== '' && !preg_match('/^[\w-]{1,32}$/', $skin)) {
            return $this->jsonFail('非法的皮肤标识', 422);
        }
        // 平台只提供白名单内皮肤（清单外直接拒绝，防止绕过前端清单写入遗留 key）
        $svc = new SiteSettingService();
        if ($skin !== '' && !SiteSettingService::isSkinAllowed($skin)) {
            return $this->jsonFail('平台未提供该皮肤', 422);
        }
        // 换肤关闭时仅拦截「换到其他皮肤」；恢复默认（空串）始终允许
        if ($skin !== '' && !$svc->skinChangeAllowed()) {
            return $this->jsonFail('平台已关闭皮肤更换功能', 403);
        }

        $this->userProfile()->saveSkin((string) $identity->id, $skin);
        return $this->jsonOk(['skin' => $skin === '' ? 'default' : $skin]);
    }

    /** 用户资料存储（data/home/profile.json）。 */
    private function userProfile(): UserProfile
    {
        return new UserProfile($this->bootstrap->dataRoot() . '/home/profile.json');
    }

    /**
     * 当前登录用户的权限清单（只读）。
     * 超管拥有全部权限点；其余用户按实际拥有的权限 code 过滤。
     */
    public function myPerms()
    {
        $identity = $this->identity();
        if ($identity === null) {
            return $this->jsonFail('未登录或会话已失效', -401);
        }

        $owned = array_flip(
            $this->bootstrap->userPerms($identity, HostBootstrap::authService())
        );

        $perms = [];
        foreach ($this->bootstrap->permissions()->all() as $p) {
            $code = (string) ($p['code'] ?? '');
            if (isset($owned[$code])) {
                $group = (string) ($p['group'] ?? '');
                $perms[] = [
                    'code'        => $code,
                    'title'       => (string) ($p['title'] ?? ''),
                    'description' => (string) ($p['description'] ?? ''),
                    'group'       => $group,
                    'groupTitle'  => self::PERM_GROUP_TITLES[$group] ?? $group,
                    'short'       => (string) ($p['short'] ?? ''),
                ];
            }
        }

        return $this->jsonOk(['perms' => $perms, 'total' => count($perms)]);
    }

    /** 平台权限分组标识 → 展示名；未命中的（功能包自定义组）直接用原值。 */
    private const PERM_GROUP_TITLES = [
        'platform' => '平台权限',
        'system'   => '系统管理',
        'host'     => '宿主功能',
    ];

    /** 首页定制存储（data/home/custom.json）。 */
    private function homeCustomization(): HomeCustomization
    {
        return new HomeCustomization($this->bootstrap->dataRoot() . '/home/custom.json');
    }

    /**
     * 由扁平可见菜单构造首页可选叶子项：
     *  - 仅保留可跳转（route 非空）的叶子菜单，父级分组不作为入口；
     *  - 补全 path（/ 开头直接用，否则 url() 生成），并按 order 排序。
     *
     * @param list<array<string, mixed>> $menus
     * @return list<array{id:string,title:string,path:string,icon:string,short:string,builtin:bool}>
     */
    private function homeMenuOptions(array $menus): array
    {
        // 平台自带菜单的包键：不算第三方功能包
        $builtinShorts = [
            HostBootstrap::ADMIN_SHORT => true,
            HostBootstrap::SEED_SHORT  => true,
        ];

        $parentIds = [];
        foreach ($menus as $m) {
            $pid = $m['parent'] ?? null;
            if ($pid !== null) {
                $parentIds[(string) $pid] = true;
            }
        }

        $out = [];
        foreach ($menus as $m) {
            $id    = (string) ($m['id'] ?? '');
            $route = (string) ($m['route'] ?? '');
            // 有子项或无路由的分组节点不可作为入口
            if ($route === '' || isset($parentIds[$id])) {
                continue;
            }
            $short = (string) ($m['short'] ?? '');
            $out[] = [
                'id'      => $id,
                'title'   => (string) ($m['title'] ?? ''),
                'path'    => str_starts_with($route, '/') ? $route : (string) url($route),
                'icon'    => (string) ($m['icon'] ?? ''),
                'short'   => $short,
                'builtin' => isset($builtinShorts[$short]),
                'order'   => (int) ($m['order'] ?? 0),
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => $a['order'] <=> $b['order'],
        );
        return $out;
    }

    /**
     * 按各包 shortcuts 声明（default / optional）构造首页卡片候选池：
     *  - 声明以菜单 id 引用，展示属性取自当前可见菜单，不可见的声明直接剔除；
     *  - 顺序遵循声明顺序（包登记顺序、组内声明顺序），同一 id 重复声明只取首次；
     *  - all = default + optional，供「定制」抽屉勾选。
     *
     * @param list<array<string, mixed>> $menus 当前用户可见菜单
     * @return array{default: list<array<string,mixed>>, optional: list<array<string,mixed>>, all: list<array<string,mixed>>}
     */
    private function homeShortcutOptions(array $menus): array
    {
        $byId = [];
        foreach ($this->homeMenuOptions($menus) as $opt) {
            $byId[(string) $opt['id']] = $opt;
        }

        $groups = ['default' => [], 'optional' => [], 'all' => []];
        $seen   = [];
        foreach ($this->bootstrap->shortcuts()->allEntries() as $entry) {
            $id = (string) $entry['id'];
            if (isset($seen[$id]) || !isset($byId[$id])) {
                continue;
            }
            $seen[$id] = true;
            $groups[$entry['group']][] = $byId[$id];
        }
        $groups['all'] = array_merge($groups['default'], $groups['optional']);
        return $groups;
    }

    /**
     * 解析首页当前应展示的入口（有序）。
     * 未定制（null）使用默认显示卡片（各包 default 声明）；已定制严格按保存顺序展示，
     * 仅剔除已失效（不再可见）的 id，未勾选项不显示。
     * 定制后新装功能包带来的默认卡片会追加到其定制列表（安装时写入），备选卡片由用户在定制抽屉自行勾选。
     *
     * @param list<array<string,mixed>> $options
     * @param list<string>|null         $custom
     * @param list<array<string,mixed>> $defaults
     * @return list<array<string,mixed>>
     */
    private function resolveSelected(array $options, ?array $custom, array $defaults = []): array
    {
        if ($custom === null) {
            return $defaults;
        }

        $byId = [];
        foreach ($options as $opt) {
            $byId[(string) $opt['id']] = $opt;
        }

        $selected = [];
        foreach ($custom as $id) {
            if (isset($byId[$id])) {
                $selected[] = $byId[$id];
            }
        }
        return $selected;
    }

    /**
     * 渲染某角色类型的独立 home 页（供工作台 iframe 加载）。
     * 各角色首页为独立完整页面，普通用户与超管均可进入，仅展示与其身份一致的内容。
     */
    private function renderRoleHome(string $tpl, string $roleLabel)
    {
        $identity = $this->identity();
        if ($identity === null) {
            return redirect('/admin/auth/login');
        }

        $homeKey = $tpl;

        // 全部可见菜单（含功能包），按各包 shortcuts 声明拆为「默认显示 / 备选」候选池
        $userPerms = $this->bootstrap->userPerms($identity, HostBootstrap::authService());
        $visible   = $this->bootstrap->visibleMenus($this->bootstrap->menus()->all(), $userPerms);
        $groups    = $this->homeShortcutOptions($visible);
        $options   = $groups['all'];

        $custom   = $this->homeCustomization()->get((string) $identity->id, $homeKey);
        $selected = $this->resolveSelected($options, $custom, $groups['default']);

        // 应用用户自定义显示名（未定制则用身份自带名称）
        $displayName = $this->userProfile()->getName((string) $identity->id) ?? $identity->name;

        // 工作台插入锚点：仅下发 render 为合法同源 URL、权限可见且角色命中的插槽（按 zone 分组、order 升序）
        $allowed = $this->bootstrap->workspace()->slotsAllowedBy(
            $userPerms,
            $this->bootstrap->roleTypesFor($identity),
        );
        $zones   = self::homeZones($allowed);
        $zonesJson = json_encode($zones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 个人审批统计卡（fail-soft：任一异常不阻断首页渲染；三开关同开才下发数据）
        $approvalStatsJson = 'null';
        try {
            $apSettings = new ApprovalSettings();
            if ($apSettings->workbenchEnabled() && $apSettings->workbenchApiEnabled() && $apSettings->workbenchStatsEnabled()) {
                $summary = (new ApprovalService())->myWorkbenchSummary($identity);
                $approvalStatsJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } catch (\Throwable) {
            $approvalStatsJson = 'null';
        }

        return $this->app->view->fetch('home/role/' . $tpl, [
            'identity'          => $identity,
            'displayName'       => $displayName,
            'roleLabel'         => $roleLabel,
            'options'           => $options,
            'selected'          => $selected,
            'optionsJson'       => json_encode(array_values($options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'defaultJson'       => json_encode(array_values($groups['default']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'selectedJson'      => json_encode(array_values($selected), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'slotsJson'         => $zonesJson,
            'homeKey'           => $homeKey,
            'approvalStatsJson' => $approvalStatsJson,
        ]);
    }

    /**
     * 把用户可见插槽按锚点（zone）分组为 home.top / home.right / home.bottom 三块。
     * 仅保留 render 为同源绝对路径（以单个 / 开头）的插槽，剔除种子/未知区与非法地址；
     * 每组内按 order 升序。输出项只含前端渲染所需字段。
     *
     * @param list<array<string,mixed>> $slots
     * @return array{'home.top':list<array{id:string,title:string,render:string,order:int}>,'home.right':list<array{id:string,title:string,render:string,order:int}>,'home.bottom':list<array{id:string,title:string,render:string,order:int}>}
     */
    private static function homeZones(array $slots): array
    {
        $zones = ['home.top' => [], 'home.right' => [], 'home.bottom' => []];
        foreach ($slots as $s) {
            $id     = (string) ($s['id'] ?? '');
            $render = (string) ($s['render'] ?? '');
            $zone   = (string) ($s['zone'] ?? '');
            // 仅接受同源路径（/ 开头且非协议相对 //x）；未知锚点一律忽略
            if ($id === '' || !isset($zones[$zone]) || !str_starts_with($render, '/')
                || str_starts_with($render, '//')) {
                continue;
            }
            $zones[$zone][] = [
                'id'     => $id,
                'title'  => (string) ($s['title'] ?? ''),
                'render' => $render,
                'order'  => (int) ($s['order'] ?? 0),
            ];
        }
        foreach ($zones as $zone => $list) {
            usort($list, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $zones[$zone] = $list;
        }
        return $zones;
    }

    /**
     * 超管首页统计聚合（纯函数，便于隔离测试）：
     *   - users   用户块：用户总数 / 角色数 / 权限组数 / 权限点数
     *   - usage   使用情况块：今日操作数 / 今日活跃操作人（去重）/ 近 7 天操作趋势 / 操作类型 Top5
     *   - services 服务使用块：附件 / 字典 / 消息 的关键计数
     *   - recent   最近操作块：最新 8 条留痕（时间 / 操作人 / 动作 / 对象）
     *
     * @param list<array<string,mixed>> $users
     * @param list<array<string,mixed>> $roles
     * @param list<array<string,mixed>> $groups
     * @param list<array<string,mixed>> $perms
     * @param list<array<string,mixed>> $trail   审计记录（含 action/operator/created_at）
     * @param array<string,mixed>       $serviceStats 各服务 stats() 原样返回
     * @return array<string,mixed>
     */
    private static function homeStatsPayload(
        array $users,
        array $roles,
        array $groups,
        array $perms,
        array $trail,
        array $serviceStats,
    ): array {
        // —— 用户块 ——
        $usersBlock = [
            'total'  => count($users),
            'roles'  => count($roles),
            'groups' => count($groups),
            'perms'  => count($perms),
        ];

        // —— 使用情况块：基于审计轨迹聚合 ——
        $today = date('Y-m-d');
        $todayOps   = 0;
        $todayActors = [];
        $bucket = [];
        for ($i = 6; $i >= 0; $i--) {
            $bucket[date('Y-m-d', time() - $i * 86400)] = 0;
        }
        $actionCount = [];
        foreach ($trail as $e) {
            $day = substr((string) ($e['created_at'] ?? ''), 0, 10);
            if (isset($bucket[$day])) {
                $bucket[$day]++;
            }
            if ($day === $today) {
                $todayOps++;
                $actor = (string) ($e['operator'] ?? '');
                if ($actor !== '') {
                    $todayActors[$actor] = true;
                }
            }
            $action = (string) ($e['action'] ?? '');
            if ($action !== '') {
                $actionCount[$action] = ($actionCount[$action] ?? 0) + 1;
            }
        }
        arsort($actionCount);
        $topActions = [];
        foreach (array_slice($actionCount, 0, 5, true) as $action => $count) {
            $topActions[] = ['action' => $action, 'count' => $count];
        }

        $usageBlock = [
            'todayOps'    => $todayOps,
            'todayActors' => count($todayActors),
            'trend'       => array_map(
                static fn (string $day, int $count): array => ['date' => $day, 'total' => $count],
                array_keys($bucket),
                array_values($bucket),
            ),
            'topActions'  => $topActions,
        ];

        // —— 最近操作块：最新 8 条留痕（新→旧） ——
        $recent = [];
        foreach (array_slice(array_reverse($trail), 0, 8) as $e) {
            $recent[] = [
                'time'     => substr((string) ($e['created_at'] ?? ''), 0, 16),
                'operator' => (string) ($e['operator'] ?? ''),
                'action'   => (string) ($e['action'] ?? ''),
                'target'   => (string) ($e['target'] ?? ''),
            ];
        }

        // —— 服务使用块：从各服务 stats() 提取计数 ——
        $attachment = (array) ($serviceStats['attachment'] ?? []);
        $dictionary = (array) ($serviceStats['dictionary'] ?? []);
        $inbox      = (array) ($serviceStats['inbox'] ?? []);
        $servicesBlock = [
            'attachment' => [
                'total'  => (int) ($attachment['total'] ?? 0),
                'active' => (int) ($attachment['active'] ?? 0),
                'bytes'  => (int) ($attachment['bytes'] ?? 0),
            ],
            'dictionary' => [
                'total'      => (int) ($dictionary['total'] ?? 0),
                'namespaces' => count((array) ($dictionary['namespaces'] ?? [])),
            ],
            'inbox' => [
                'total'      => (int) ($inbox['message_total'] ?? 0),
                'recipients' => (int) ($inbox['recipient_total'] ?? 0),
                'unread'     => (int) ($inbox['unread_total'] ?? 0),
            ],
        ];

        return [
            'users'    => $usersBlock,
            'usage'    => $usageBlock,
            'services' => $servicesBlock,
            'recent'   => $recent,
        ];
    }
}