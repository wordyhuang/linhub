<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\lifecycle\LifecycleState;
use linhub\host\lifecycle\PackageStateStore;
use linhub\host\rbac\DbRbacStorage;
use linhub\host\rbac\RbacStorage;
use linhub\host\services\approval\ApprovalService;
use linhub\host\services\approval\ApprovalSettings;
use think\facade\Env;

/**
 * 平台审批中心（AP 管理 GUI）—— admin 应用
 *
 * 门禁分两级：
 *  - 登录门禁（loginGate/loginGateForJson，全体登录用户，数据级越权由服务层兜底）：
 *      workbench / detail 页、pendingRows / handledRows / myCounts / detailData / approve / reject / cancel
 *  - 超管门禁（gate/gateForJson）：index / flowIndex / flowEditor / flowList / flowMeta / flowSave / rows / settings / settingsData / settingsSave
 *
 *  - GET  /admin/approval_admin/index        全部单据页（平台全量检索 + 服务统计）【超管】
 *  - GET  /admin/approval_admin/workbench    审批工作台（我的待办/我的已办/已同意/已驳回四 tab；受服务设置开关控制）【登录】
 *  - GET  /admin/approval_admin/detail       单据详情页【登录】
 *  - GET  /admin/approval_admin/flowEditor   流程设计页（图形化流程编辑器）【超管】
 *  - GET  /admin/approval_admin/rows         全部单据分页检索 JSON + stats【超管】
 *  - GET  /admin/approval_admin/pendingRows  我的待办 JSON（按审批人快照跨包检索）【登录】
 *  - GET  /admin/approval_admin/handledRows  我的已办 JSON（action=approve/reject 按最近动作过滤）【登录】
 *  - GET  /admin/approval_admin/myCounts     工作台计数摘要 JSON（待办/已办/已同意/已驳回）【登录】
 *  - GET  /admin/approval_admin/detailData   单据详情 JSON（单据 + 节点 + 动作 + can_act）【登录】
 *  - GET  /admin/approval_admin/flowList     流程模板列表 JSON（跨包全量）【超管】
 *  - GET  /admin/approval_admin/flowMeta     审批人下拉元数据 JSON（角色 + 权限组 + 用户）【超管】
 *  - GET  /admin/approval_admin/settings     审批服务设置页（服务配置开关）【超管】
 *  - GET  /admin/approval_admin/settingsData 服务设置 JSON（全量配置，接入平台键值对服务）【超管】
 *  - POST /admin/approval_admin/settingsSave 保存服务设置【超管】
 *  - POST /admin/approval_admin/flowSave     保存流程模板（defineFlow）【超管】
 *  - POST /admin/approval_admin/approve      同意（意见选填）【登录，服务层校验审批人快照】
 *  - POST /admin/approval_admin/reject       驳回（意见必填，整单驳回）【登录，服务层校验审批人快照】
 *  - POST /admin/approval_admin/cancel       撤回（仅 pending）【登录，服务层校验仅提交人】
 *
 * 身份一律取当前登录态构造 Identity 传入服务层，与功能包侧调用契约完全同一条链路（D8 双入口一致性）。
 */
class ApprovalAdmin extends AdminController
{
    private function service(): ApprovalService
    {
        return new ApprovalService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/index');
    }

    /** 审批工作台：我的待办/我的已办双 tab（口径均为当前登录用户）；服务设置停用时渲染提示页。 */
    public function workbench()
    {
        $gate = $this->loginGate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        if (!$this->settingsSvc()->workbenchEnabled()) {
            return $this->app->view->fetch('approval_admin/workbench_disabled');
        }
        return $this->app->view->fetch('approval_admin/workbench');
    }

    /** 单据详情页（工作台/全部单据列表跳转目标）。 */
    public function detail()
    {
        $gate = $this->loginGate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/detail');
    }

    /** 流程清单页：自定义与系统定义双 tab，仅超管（自定义可编辑，系统定义只读）。 */
    public function flowIndex()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/flow_index');
    }

    /** 流程设计页（图形化流程编辑器）。 */
    public function flowEditor()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/flow_editor');
    }

    /** 审批服务设置页：服务配置开关（接入平台键值对服务持久化）。 */
    public function settings()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('approval_admin/settings');
    }

    /** JSON：服务设置全量配置。 */
    public function settingsData()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $data = $this->settingsSvc()->all();
        } catch (\Throwable $e) {
            return $this->jsonFail('读取服务设置失败：' . $e->getMessage());
        }
        return $this->jsonOk($data);
    }

    /** 保存服务设置：白名单合并后整体持久化到平台键值对服务。 */
    public function settingsSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $saved = $this->settingsSvc()->save([
                'workbench_enabled'       => (bool) $this->request->post('workbench_enabled'),
                'workbench_api_enabled'   => (bool) $this->request->post('workbench_api_enabled'),
                'workbench_stats_enabled' => (bool) $this->request->post('workbench_stats_enabled'),
                'notify_enabled'          => (bool) $this->request->post('notify_enabled'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.settingsSave', ApprovalSettings::NS . '/' . ApprovalSettings::KEY);
        return $this->jsonOk($saved, '已保存');
    }

    /** 审批服务设置存取封装。 */
    private function settingsSvc(): ApprovalSettings
    {
        return new ApprovalSettings();
    }

    /** JSON：流程模板列表（host 跨包全量），附去重后的包名清单。 */
    public function flowList()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $flows = $this->service()->flows(null);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取流程模板失败：' . $e->getMessage());
        }
        $packages = [];
        foreach ($flows as $flow) {
            $pkg = (string) ($flow['package'] ?? '');
            if ($pkg !== '' && !in_array($pkg, $packages, true)) {
                $packages[] = $pkg;
            }
        }
        // 平台尚无任何流程模板时并入已安装功能包，保证新建流程的所属包候选不为空
        try {
            foreach ((new PackageStateStore($this->stateFile()))->read() as $record) {
                $state = (string) ($record['state'] ?? '');
                if ($state !== LifecycleState::ENABLED && $state !== LifecycleState::DISABLED) {
                    continue;
                }
                $pkg = (string) ($record['short'] ?? '');
                if ($pkg !== '' && !in_array($pkg, $packages, true)) {
                    $packages[] = $pkg;
                }
            }
        } catch (\Throwable $e) {
            // 包状态文件缺失或损坏时忽略，仅影响下拉候选
        }
        sort($packages);
        return $this->jsonOk(['list' => $flows, 'packages' => $packages]);
    }

    /** JSON：审批人下拉元数据（平台角色 + 用户）。 */
    public function flowMeta()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $rbac = new DbRbacStorage();
            $roles = [];
            foreach ($rbac->section(RbacStorage::ROLES) as $roleId => $payload) {
                $roles[] = [
                    'id'   => (string) $roleId,
                    'name' => (string) ((is_array($payload) ? $payload['name'] : '') ?: $roleId),
                ];
            }
            $users = [];
            foreach ($rbac->section(RbacStorage::USERS) as $userId => $payload) {
                $users[] = [
                    'id'   => (string) $userId,
                    'name' => (string) ((is_array($payload) ? $payload['name'] : '') ?: $userId),
                ];
            }
            $groups = [];
            foreach ($rbac->section(RbacStorage::GROUPS) as $groupId => $payload) {
                $groups[] = [
                    'id'   => (string) $groupId,
                    'name' => (string) ((is_array($payload) ? $payload['name'] : '') ?: $groupId),
                ];
            }
        } catch (\Throwable $e) {
            return $this->jsonFail('读取审批人元数据失败：' . $e->getMessage());
        }
        return $this->jsonOk(['roles' => $roles, 'users' => $users, 'groups' => $groups]);
    }

    /** 保存流程模板：前端提交 package + flow（DSL JSON 字符串）。 */
    public function flowSave()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $package = trim((string) $this->request->post('package', ''));
        if ($package === '') {
            return $this->jsonFail('缺少所属功能包 package');
        }
        $raw = trim((string) $this->request->post('flow', ''));
        if ($raw === '') {
            return $this->jsonFail('缺少流程定义');
        }
        $flow = json_decode($raw, true);
        if (!is_array($flow)) {
            return $this->jsonFail('流程定义不是合法的 JSON');
        }
        try {
            $saved = $this->service()->defineFlow($package, $flow, 'admin');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.flowSave', $package . '/' . (string) ($saved['flow_key'] ?? ''));
        return $this->jsonOk($saved, '已保存');
    }

    /** JSON：全部单据（超管视角跨包全量）。参数兼容 wd-data-grid 内建分页契约（param 嵌套优先、currentPage/pageSize），平铺写法兜底。state 页签映射：running=在办、todo=待办（当前用户名下有活跃审批任务）、approved/rejected/cancelled=同名终态。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $param = is_array($this->request->get('param')) ? $this->request->get('param') : [];
        $filter = [];
        foreach (['state', 'status', 'flow_key', 'submitter_id', 'keyword', 'date_from', 'date_to', 'orphaned', 'package'] as $key) {
            $value = trim((string) ($param[$key] ?? $this->request->get($key, '')));
            if ($value !== '') {
                $filter[$key] = $value;
            }
        }
        $page = max(1, (int) ($param['currentPage'] ?? $this->request->get('currentPage', $this->request->get('page', 1))));
        $size = max(1, min(200, (int) ($param['pageSize'] ?? $this->request->get('pageSize', $this->request->get('size', 20)))));

        // state 页签 → 检索过滤（status 保留为直调兼容，state 优先级更高）
        $state = (string) ($filter['state'] ?? '');
        unset($filter['state']);
        if ($state === 'running') {
            $filter['status'] = 'pending';
        } elseif ($state === 'todo') {
            $nos = $this->service()->myPendingNos($gate);
            if ($nos === []) {
                return $this->jsonOk(['list' => [], 'total' => 0, 'currentPage' => $page, 'pageSize' => $size]);
            }
            $filter['status'] = 'pending';
            $filter['approval_nos'] = implode(',', $nos);
        } elseif (in_array($state, ['approved', 'rejected', 'cancelled'], true)) {
            $filter['status'] = $state;
        }

        try {
            $result = $this->service()->list(null, $filter, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：审批单据用量统计（统计卡与状态页签数字数据源；rows 已收敛为 DataGrid 内建分页，stats 独立供给，todo 为当前登录账号名下活跃待办数）。 */
    public function stats()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $stats = $this->service()->stats($gate);
        } catch (\Throwable $e) {
            return $this->jsonFail('统计失败：' . $e->getMessage());
        }
        return $this->jsonOk($stats);
    }

    /** JSON：我的待办（当前登录用户）；数据接口开关停用时拒绝。 */
    public function pendingRows()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        if (!$this->settingsSvc()->workbenchApiEnabled()) {
            return $this->jsonFail('我的待办 / 我的已办数据接口已停用', 403);
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        try {
            $result = $this->service()->myPending($gate, $page, $size);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：我的已办（当前登录用户）；action=approve/reject 按最近动作过滤；数据接口开关停用时拒绝。 */
    public function handledRows()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        if (!$this->settingsSvc()->workbenchApiEnabled()) {
            return $this->jsonFail('我的待办 / 我的已办数据接口已停用', 403);
        }
        $page = max(1, (int) $this->request->get('page', 1));
        $size = max(1, min(200, (int) $this->request->get('size', 20)));
        $action = (string) $this->request->get('action', '');
        try {
            $result = $this->service()->myHandled($gate, $page, $size, $action);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
        ]);
    }

    /** JSON：工作台计数摘要（待办/已办/已同意/已驳回），页签徽标与角色首页统计卡共用；数据接口开关停用时拒绝。 */
    public function myCounts()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        if (!$this->settingsSvc()->workbenchApiEnabled()) {
            return $this->jsonFail('我的待办 / 我的已办数据接口已停用', 403);
        }
        try {
            $summary = $this->service()->myWorkbenchSummary($gate);
        } catch (\Throwable $e) {
            return $this->jsonFail('统计失败：' . $e->getMessage());
        }
        return $this->jsonOk($summary);
    }

    /** JSON：单据详情（payload / 节点链 / 动作时间线 / 当前用户可否审批）。 */
    public function detailData()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->get('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        try {
            $row = $this->service()->get(null, $no);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
        if ($row === null) {
            return $this->jsonFail('单据不存在', 404);
        }
        $row['can_act'] = $this->service()->canAct($gate, $no);
        return $this->jsonOk($row);
    }

    /** 同意（意见选填；服务层校验审批人在节点快照内）。 */
    public function approve()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        try {
            $this->service()->approve($gate, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('审批失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.approve', $no);
        return $this->jsonOk(null, '已同意');
    }

    /** 驳回（意见必填，整单驳回；服务层校验审批人在节点快照内）。 */
    public function reject()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        if ($comment === '') {
            return $this->jsonFail('驳回必须填写审批意见');
        }
        try {
            $this->service()->reject($gate, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('驳回失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.reject', $no);
        return $this->jsonOk(null, '已驳回');
    }

    /** 撤回（仅 pending 且未冻结；服务层校验仅提交人可撤回）。 */
    public function cancel()
    {
        $gate = $this->loginGateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $no = trim((string) $this->request->post('no', ''));
        if ($no === '') {
            return $this->jsonFail('缺少单据编号');
        }
        $comment = trim((string) $this->request->post('comment', ''));
        try {
            $this->service()->cancelAs($gate, null, $no, $comment);
        } catch (\Throwable $e) {
            return $this->jsonFail('撤回失败：' . $e->getMessage());
        }
        $this->audit($gate, 'approval.cancel', $no);
        return $this->jsonOk(null, '已撤回');
    }

    /** 审批操作留痕（登录用户，失败不阻断）。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }

    /** 宿主根目录（code/host），与包管理控制器同口径。 */
    private function baseDir(): string
    {
        return dirname(__DIR__, 4);
    }

    /** 功能包状态文件路径（.env PACKAGE_STATE_FILE 可覆盖，默认 data/plugin-state.json）。 */
    private function stateFile(): string
    {
        return $this->envPath('PACKAGE_STATE_FILE', $this->baseDir() . '/data/plugin-state.json');
    }

    /** 读取 .env 配置项（带默认值回退）；相对路径基于宿主根解析。 */
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
        return $this->normalizePath($val);
    }

    /** 判断路径是否为绝对路径（Windows 盘符或 Unix 根开头的正斜杠/反斜杠）。 */
    private function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[/\\\\]|/|\\\\)#', $path) === 1;
    }

    /** 规范化路径：反斜杠统一正斜杠；路径存在取 realpath，否则字符串级消解 ../ 与 ./ 段。 */
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
}
