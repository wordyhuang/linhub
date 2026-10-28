<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

use linhub\capability\auth\Identity;
use linhub\host\rbac\DbRbacStorage;
use linhub\host\rbac\RbacStorage;
use linhub\host\services\inbox\InboxService;
use linhub\host\services\logging\LoggingService;
use linhub\host\services\messaging\MessagingService;
use linhub\host\services\support\PlatformStore;

/**
 * 审批服务实现（AP-1 ~ AP-16）。
 *
 * 四张表（PlatformStore 已建好）：
 *  - lg_mgt_appr_flow：流程模板定义（JSON DSL，覆盖式更新 version 自增）；
 *  - lg_mgt_appr_instance：审批单（payload 快照 + 状态 + 提交人）；
 *  - lg_mgt_appr_node_instance：节点实例（激活时落审批人快照，含 join 汇合标记行）；
 *  - lg_mgt_appr_action：动作流水（submit/approve/reject/cancel，永久保留）。
 *
 * 引擎执行模型：
 *  - 节点「到达即执行」：审批节点到达时立即建实例并激活（不预建 waiting 行）；
 *  - 排他网关按声明顺序取首个命中分支，未命中走 condition=null 默认分支；
 *  - 并行网关逐支推进，各分支到达汇合点时插入 node_type=join 的标记行，
 *    distinct 分支计数达到 branches 数后清零标记并进入 join 后继节点；
 *  - 单据完成判定统一在动作末尾 finalizeInstance()：pending + 无冻结 + 无
 *    active/waiting/error 节点 → approved。
 *
 * 通知采用「先入队、提交后发送」：DB 事务 commit 后才 publish 事件与投递 inbox，
 * 避免回滚后留下脏通知；单条通知失败只记日志，不影响主流程。
 *
 * 无参构造（自读 env 建 PlatformStore），满足 ServiceInjector 的 app->make()。
 */
final class ApprovalService implements Approval
{
    /** host 保留命名空间：平台自身单据。 */
    public const HOST_NAMESPACE = 'host';

    /** payload 单条上限（json_encode 后字节数）：1MB。 */
    public const MAX_PAYLOAD_BYTES = 1048576;

    /** 单据提交事件 topic。 */
    public const EVENT_SUBMITTED = 'lg.platform.approval.submitted';

    /** 单据终态事件 topic。 */
    public const EVENT_COMPLETED = 'lg.platform.approval.completed';

    /** 虚拟汇合标识前缀：__join_<网关id>__ */
    private const JOIN_PREFIX = '__join_';

    private const JOIN_SUFFIX = '__';

    /** 条件比较运算符白名单。 */
    private const CONDITION_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'exists'];

    /** 条件嵌套深度上限。 */
    private const MAX_CONDITION_DEPTH = 3;

    /** 流程定义静态遍历深度上限（防脏定义导致栈溢出）。 */
    private const MAX_GRAPH_WALK = 256;

    private PlatformStore $store;

    private ?RbacStorage $rbac = null;

    /** 自定义审批人解析器：name => handler class（进程内静态注册表，同名覆盖）。 */
    private static array $resolvers = [];

    /** 待发布事件队列：['topic'=>string,'payload'=>array]。 */
    private array $queuedEvents = [];

    /** 待投递站内信队列：['package'=>string,'message'=>array,'userIds'=>list<string>]。 */
    private array $queuedInbox = [];

    /** 解析器失败记录（submit 提交后统一抛 ApprovalResolverError）。 */
    private array $resolverErrors = [];

    /** submit 期间收集的首批激活节点快照（submitted 事件载荷）。 */
    private array $activatedSnapshots = [];

    /** 是否收集激活快照（仅 submit 期间开启）。 */
    private bool $collectActivated = false;

    /** RBAC 用户分区缓存（单次请求内复用）。 */
    private ?array $usersCache = null;

    public function __construct(?PlatformStore $store = null)
    {
        $this->store = $store ?? new PlatformStore();
    }

    // —— 契约实现 ——

    public function defineFlow(?string $package, array $flow): array
    {
        $scope = $this->resolveScope($package);
        $definition = $this->normalizeFlow($flow);
        $this->validateFlow($definition);

        $flowKey = (string) $definition['flow_key'];
        $json = (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('c');
        $db = $this->store->db();
        $existing = $this->flowRow($scope, $flowKey);

        if ($existing === null) {
            $db->exec(
                'INSERT INTO ' . PlatformStore::APPR_FLOW_TABLE
                . ' (package, flow_key, name, definition, version, status, created_at, updated_at) VALUES ('
                . $this->store->quote($scope) . ', '
                . $this->store->quote($flowKey) . ', '
                . $this->store->quote((string) $definition['name']) . ', '
                . $this->store->quote($json) . ', 1, '
                . "'active', "
                . $this->store->quote($now) . ', '
                . $this->store->quote($now) . ')'
            );
        } else {
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_FLOW_TABLE
                . ' SET name=' . $this->store->quote((string) $definition['name'])
                . ', definition=' . $this->store->quote($json)
                . ', version=' . ((int) $existing['version'] + 1)
                . ", status='active'"
                . ', updated_at=' . $this->store->quote($now)
                . ' WHERE id=' . (int) $existing['id']
            );
        }

        $row = $this->flowRow($scope, $flowKey);
        if ($row === null) {
            throw new ApprovalState('流程模板写入失败：' . $flowKey);
        }
        $this->journal('defineFlow', ['package' => $scope, 'flow_key' => $flowKey, 'version' => (int) $row['version']]);
        return $this->decorateFlow($row);
    }

    public function submit(?string $package, string $flowKey, array $payload, array $options = []): string
    {
        $scope = $this->resolveScope($package);
        $flowKey = trim($flowKey);
        if ($flowKey === '') {
            throw new ApprovalFlowInvalid('flow_key 不能为空');
        }

        $flowRow = $this->flowRow($scope, $flowKey);
        if ($flowRow === null) {
            throw new ApprovalFlowInvalid("流程未注册：{$scope}/{$flowKey}");
        }
        if ((string) ($flowRow['status'] ?? 'active') !== 'active') {
            throw new ApprovalFlowInvalid("流程已停用：{$scope}/{$flowKey}");
        }
        $definition = $this->decodeFlowDefinition((string) ($flowRow['definition'] ?? ''));
        if ($definition === null) {
            throw new ApprovalFlowInvalid("流程定义无法解析：{$scope}/{$flowKey}");
        }

        $title = trim((string) ($options['title'] ?? ''));
        if ($title === '') {
            throw new ApprovalState('submit 缺少必填项 title（单据标题）');
        }
        if ($this->textLen($title) > 255) {
            throw new ApprovalState('单据标题不能超过 255 字符');
        }
        $summary = $this->textSlice(trim((string) ($options['summary'] ?? '')), 500);
        $submitterId = $this->textSlice(trim((string) ($options['submitterId'] ?? '')), 191);
        if ($submitterId === '') {
            $submitterId = 'anonymous';
        }
        $submitterName = $this->textSlice(trim((string) ($options['submitterName'] ?? '')), 191);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ApprovalState('payload 无法 JSON 编码：' . json_last_error_msg());
        }
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new ApprovalState('payload 超过单条上限 ' . self::MAX_PAYLOAD_BYTES . ' 字节（1MB）');
        }

        $approvalNo = 'APR' . bin2hex(random_bytes(16));
        $now = date('c');
        $db = $this->store->db();
        $this->resetQueues();
        $this->activatedSnapshots = [];
        $this->collectActivated = true;
        $instanceId = 0;

        try {
            $db->begin();
            $db->exec(
                'INSERT INTO ' . PlatformStore::APPR_INSTANCE_TABLE
                . ' (approval_no, package, flow_key, flow_version, title, summary, payload, payload_size,'
                . ' submitter_id, submitter_name, status, orphaned, submitted_at, finished_at, finish_comment) VALUES ('
                . $this->store->quote($approvalNo) . ', '
                . $this->store->quote($scope) . ', '
                . $this->store->quote($flowKey) . ', '
                . (int) $flowRow['version'] . ', '
                . $this->store->quote($title) . ', '
                . $this->store->quote($summary) . ', '
                . $this->store->quote($json) . ', '
                . strlen($json) . ', '
                . $this->store->quote($submitterId) . ', '
                . $this->store->quote($submitterName) . ", "
                . "'pending', 0, "
                . $this->store->quote($now) . ", '', '')"
            );
            $rows = $db->query(
                'SELECT id FROM ' . PlatformStore::APPR_INSTANCE_TABLE
                . ' WHERE approval_no=' . $this->store->quote($approvalNo) . ' LIMIT 1'
            );
            $instanceId = (int) ($rows[0]['id'] ?? 0);
            if ($instanceId <= 0) {
                throw new ApprovalState('审批单写入失败：' . $approvalNo);
            }

            $this->insertAction($instanceId, 0, $submitterId, $submitterName, 'submit', '', $now);
            $this->runNode($instanceId, $definition, (string) $definition['entry'], [], $this->rootCtx(), [
                'package' => $scope,
                'flow_key' => $flowKey,
                'approval_no' => $approvalNo,
                'title' => $title,
                'payload' => $payload,
                'submitter_id' => $submitterId,
                'submitter_name' => $submitterName,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // 回滚失败不掩盖原始异常
            }
            $this->collectActivated = false;
            $this->resetQueues();
            throw $e;
        }

        $this->collectActivated = false;
        $instance = $this->instanceById($instanceId);
        // 解析器失败记录须在 flushNotifications（内部 resetQueues）之前取走
        $resolverErrors = $this->resolverErrors;

        $this->queueEvent(self::EVENT_SUBMITTED, [
            'approval_no' => $approvalNo,
            'package' => $scope,
            'flow_key' => $flowKey,
            'title' => $title,
            'submitter_id' => $submitterId,
            'first_nodes' => $this->activatedSnapshots,
        ]);
        $this->finalizeInstance($instanceId);
        $this->flushNotifications();
        $this->journal('submit', [
            'approval_no' => $approvalNo,
            'package' => $scope,
            'flow_key' => $flowKey,
            'submitter_id' => $submitterId,
        ]);

        if ($resolverErrors !== []) {
            throw new ApprovalResolverError(
                "单据 {$approvalNo} 部分节点审批人解析失败：" . implode('；', $resolverErrors)
            );
        }

        return $approvalNo;
    }

    public function get(?string $package, string $approvalNo): ?array
    {
        $approvalNo = trim($approvalNo);
        if ($approvalNo === '') {
            return null;
        }
        $row = $this->instanceByNo($approvalNo);
        if ($row === null) {
            return null;
        }
        $this->assertExplicitPackage($package, (string) $row['package']);
        $this->assertInstanceAccess((string) $row['package']);
        return $this->decorateInstanceFull($row);
    }

    public function list(?string $package, array $filter, int $page = 1, int $size = 20): array
    {
        // 审批中心「全部单据」：package=null 且 caller 为 host 时跨包全量
        $scope = $this->resolveScope($package, true);
        $db = $this->store->db();
        $table = PlatformStore::APPR_INSTANCE_TABLE;

        $where = [];
        if ($scope !== '') {
            $where[] = 'package=' . $this->store->quote($scope);
        } else {
            // 跨包全量视图（审批中心「全部单据」）允许再按包名收窄
            $pkg = trim((string) ($filter['package'] ?? ''));
            if ($pkg !== '') {
                $where[] = 'package=' . $this->store->quote($pkg);
            }
        }
        $status = trim((string) ($filter['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status=' . $this->store->quote($status);
        }
        $flowKey = trim((string) ($filter['flow_key'] ?? ''));
        if ($flowKey !== '') {
            $where[] = 'flow_key=' . $this->store->quote($flowKey);
        }
        $submitterId = trim((string) ($filter['submitter_id'] ?? ''));
        if ($submitterId !== '') {
            $where[] = 'submitter_id=' . $this->store->quote($submitterId);
        }
        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $q = $this->store->quote($kw);
            $where[] = '(approval_no LIKE ' . $q . ' OR title LIKE ' . $q
                . ' OR summary LIKE ' . $q . ' OR submitter_name LIKE ' . $q . ')';
        }
        $dateFrom = trim((string) ($filter['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'submitted_at >= ' . $this->store->quote($dateFrom);
        }
        $dateTo = trim((string) ($filter['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'submitted_at <= ' . $this->store->quote($dateTo);
        }
        $orphaned = trim((string) ($filter['orphaned'] ?? ''));
        if ($orphaned === 'yes') {
            $where[] = 'orphaned != 0';
        } elseif ($orphaned === 'no') {
            $where[] = 'orphaned = 0';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $countRows = $db->query('SELECT COUNT(*) AS c FROM ' . $table . $whereSql);
        $total = (int) ($countRows[0]['c'] ?? 0);
        $rows = $db->query(
            'SELECT * FROM ' . $table . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );

        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'items' => array_map(fn (array $r): array => $this->decorateBrief($r), $rows),
        ];
    }

    public function cancel(?string $package, string $approvalNo, string $comment = ''): bool
    {
        return $this->cancelAs(null, $package, $approvalNo, $comment);
    }

    public function approve(Identity $approver, string $approvalNo, string $comment = ''): bool
    {
        $instance = $this->instanceByNo(trim($approvalNo));
        if ($instance === null) {
            throw new ApprovalState('审批单不存在：' . $approvalNo);
        }
        $this->assertOperable($instance);

        $instanceId = (int) $instance['id'];
        $nodeRow = $this->matchActiveNode($instanceId, $approver);
        if ($nodeRow === null) {
            // 幂等：之前已审批过（含并行场景中另一节点已处理）时直接返回成功
            if ($this->hadAction($instanceId, $approver, 'approve')) {
                return true;
            }
            throw new ApprovalDenied('当前用户不在该单据在途节点的审批人快照内：' . $approvalNo);
        }

        $nodeInstanceId = (int) $nodeRow['id'];
        if ($this->alreadyActed($nodeInstanceId, $approver, 'approve')) {
            return true;
        }

        $snapshot = $this->decodeList((string) ($nodeRow['approver_snapshot'] ?? ''));
        $db = $this->store->db();
        $now = date('c');
        $passed = false;
        $this->resetQueues();

        try {
            $db->begin();
            $this->insertAction(
                $instanceId,
                $nodeInstanceId,
                $approver->id,
                $approver->name,
                'approve',
                $this->textSlice(trim($comment), 2000),
                $now
            );
            $passed = (string) ($nodeRow['mode'] ?? 'any') === 'any'
                || $this->allApproved($nodeInstanceId, $snapshot);
            if ($passed) {
                $db->exec(
                    'UPDATE ' . PlatformStore::APPR_NODE_TABLE . " SET status='approved', finished_at="
                    . $this->store->quote($now)
                    . ' WHERE id=' . $nodeInstanceId
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // 回滚失败不掩盖原始异常
            }
            $this->resetQueues();
            throw $e;
        }

        if ($passed) {
            $this->stepFromNode($instance, $nodeRow);
        }
        $this->finalizeInstance($instanceId);
        $this->flushNotifications();
        $this->journal('approve', [
            'approval_no' => (string) $instance['approval_no'],
            'node_id' => (string) $nodeRow['node_id'],
            'actor_id' => $approver->id,
            'node_passed' => $passed,
        ]);
        return true;
    }

    public function reject(Identity $approver, string $approvalNo, string $comment): bool
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new ApprovalState('驳回必须填写审批意见');
        }

        $instance = $this->instanceByNo(trim($approvalNo));
        if ($instance === null) {
            throw new ApprovalState('审批单不存在：' . $approvalNo);
        }
        $this->assertOperable($instance);

        $instanceId = (int) $instance['id'];
        $nodeRow = $this->matchActiveNode($instanceId, $approver);
        if ($nodeRow === null) {
            if ($this->hadAction($instanceId, $approver, 'reject')) {
                return true;
            }
            throw new ApprovalDenied('当前用户不在该单据在途节点的审批人快照内：' . $approvalNo);
        }

        $nodeInstanceId = (int) $nodeRow['id'];
        if ($this->alreadyActed($nodeInstanceId, $approver, 'reject')) {
            return true;
        }

        $db = $this->store->db();
        $now = date('c');
        $this->resetQueues();

        try {
            $db->begin();
            $this->insertAction(
                $instanceId,
                $nodeInstanceId,
                $approver->id,
                $approver->name,
                'reject',
                $this->textSlice($comment, 2000),
                $now
            );
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_NODE_TABLE . " SET status='rejected', finished_at="
                . $this->store->quote($now) . ' WHERE id=' . $nodeInstanceId
            );
            // 整单驳回：其余在途 / 未达节点归档为 skipped
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_NODE_TABLE . " SET status='skipped', finished_at="
                . $this->store->quote($now)
                . " WHERE instance_id=" . $instanceId . " AND status IN ('active', 'waiting')"
            );
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_INSTANCE_TABLE . " SET status='rejected', finished_at="
                . $this->store->quote($now)
                . ', finish_comment=' . $this->store->quote($this->textSlice($comment, 2000))
                . ' WHERE id=' . $instanceId
            );
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // 回滚失败不掩盖原始异常
            }
            $this->resetQueues();
            throw $e;
        }

        $this->queueEvent(self::EVENT_COMPLETED, [
            'approval_no' => (string) $instance['approval_no'],
            'package' => (string) $instance['package'],
            'status' => 'rejected',
            'finished_at' => $now,
        ]);
        $this->queueSubmitterNotice($instance, 'rejected', $comment);
        $this->flushNotifications();
        $this->journal('reject', [
            'approval_no' => (string) $instance['approval_no'],
            'node_id' => (string) $nodeRow['node_id'],
            'actor_id' => $approver->id,
        ]);
        return true;
    }

    public function myPending(Identity $viewer, int $page = 1, int $size = 20): array
    {
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT n.id AS node_instance_id, n.node_id, n.mode, n.approver_snapshot,'
            . ' n.activated_at AS node_activated_at, i.*'
            . ' FROM ' . PlatformStore::APPR_NODE_TABLE . ' n'
            . ' INNER JOIN ' . PlatformStore::APPR_INSTANCE_TABLE . ' i ON i.id = n.instance_id'
            . " WHERE n.status='active' AND n.node_type='approval'"
            . " AND i.status='pending' AND i.orphaned=0"
            . ' ORDER BY n.activated_at ASC, n.id ASC'
        );

        $matched = [];
        foreach ($rows as $row) {
            $snapshot = $this->decodeList((string) ($row['approver_snapshot'] ?? ''));
            if (!$this->identityInSnapshot($viewer, $snapshot)) {
                continue;
            }
            $no = (string) ($row['approval_no'] ?? '');
            if ($no === '' || isset($matched[$no])) {
                continue;
            }
            $matched[$no] = $this->decoratePendingRow($row);
        }

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $total = count($matched);
        $items = array_slice(array_values($matched), ($page - 1) * $size, $size);
        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $items];
    }

    public function registerResolver(string $name, string $handlerClass): void
    {
        $name = trim($name);
        $handlerClass = trim($handlerClass);
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) {
            throw new ApprovalResolverError('解析器名称只能包含字母、数字、点、横线、下划线，且不超过 64 字符');
        }
        if ($handlerClass === '' || !class_exists($handlerClass)) {
            throw new ApprovalResolverError("解析器类不存在：{$handlerClass}");
        }
        self::$resolvers[$name] = $handlerClass;
    }

    public function stats(): array
    {
        $db = $this->store->db();
        $table = PlatformStore::APPR_INSTANCE_TABLE;
        $totals = $db->query(
            "SELECT COUNT(*) AS c,"
            . " SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,"
            . " SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,"
            . " SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,"
            . " SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled,"
            . ' SUM(CASE WHEN orphaned != 0 THEN 1 ELSE 0 END) AS orphaned'
            . ' FROM ' . $table
        );
        $byPackage = $db->query(
            'SELECT package, COUNT(*) AS c,'
            . " SUM(CASE WHEN status='pending' AND orphaned=0 THEN 1 ELSE 0 END) AS pending,"
            . " SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,"
            . " SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected"
            . ' FROM ' . $table . ' GROUP BY package ORDER BY c DESC'
        );
        $t = $totals[0] ?? [];

        return [
            'total' => (int) ($t['c'] ?? 0),
            'pending' => (int) ($t['pending'] ?? 0),
            'approved' => (int) ($t['approved'] ?? 0),
            'rejected' => (int) ($t['rejected'] ?? 0),
            'cancelled' => (int) ($t['cancelled'] ?? 0),
            'orphaned' => (int) ($t['orphaned'] ?? 0),
            'by_status' => [
                'pending' => (int) ($t['pending'] ?? 0),
                'approved' => (int) ($t['approved'] ?? 0),
                'rejected' => (int) ($t['rejected'] ?? 0),
                'cancelled' => (int) ($t['cancelled'] ?? 0),
            ],
            'by_package' => array_map(static fn (array $r): array => [
                'package' => (string) $r['package'],
                'total' => (int) $r['c'],
                'pending' => (int) ($r['pending'] ?? 0),
                'approved' => (int) ($r['approved'] ?? 0),
                'rejected' => (int) ($r['rejected'] ?? 0),
            ], $byPackage),
        ];
    }

    // —— 实现类扩展接口（不进 Approval 契约） ——

    /** 撤回并记录真实操作人（审批中心控制器用）；$actor=null 时以调用方包名兜底。 */
    public function cancelAs(?Identity $actor, ?string $package, string $approvalNo, string $comment = ''): bool
    {
        $instance = $this->instanceByNo(trim($approvalNo));
        if ($instance === null) {
            throw new ApprovalState('审批单不存在：' . $approvalNo);
        }
        $this->assertExplicitPackage($package, (string) $instance['package']);
        $this->assertInstanceAccess((string) $instance['package']);
        $this->assertOperable($instance);

        $instanceId = (int) $instance['id'];
        $db = $this->store->db();
        $now = date('c');
        $comment = $this->textSlice(trim($comment), 2000);
        $actorId = $actor?->id ?? $this->callerFromTrace();
        $actorName = $actor?->name ?? '';
        $this->resetQueues();

        try {
            $db->begin();
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_NODE_TABLE . " SET status='skipped', finished_at="
                . $this->store->quote($now)
                . ' WHERE instance_id=' . $instanceId . " AND status IN ('active', 'waiting')"
            );
            $this->insertAction($instanceId, 0, $actorId, $actorName, 'cancel', $comment, $now);
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_INSTANCE_TABLE . " SET status='cancelled', finished_at="
                . $this->store->quote($now) . ', finish_comment=' . $this->store->quote($comment)
                . ' WHERE id=' . $instanceId
            );
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // 回滚失败不掩盖原始异常
            }
            $this->resetQueues();
            throw $e;
        }

        $this->queueEvent(self::EVENT_COMPLETED, [
            'approval_no' => (string) $instance['approval_no'],
            'package' => (string) $instance['package'],
            'status' => 'cancelled',
            'finished_at' => $now,
        ]);
        $this->queueSubmitterNotice($instance, 'cancelled', $comment);
        $this->flushNotifications();
        $this->journal('cancel', [
            'approval_no' => (string) $instance['approval_no'],
            'actor_id' => $actorId,
        ]);
        return true;
    }

    /**
     * 我的已办：我 approve/reject 过的单据（按单据去重，取我最近一次动作）。
     *
     * @return array{total:int, page:int, size:int, items:list<array<string,mixed>>}
     */
    public function myHandled(Identity $viewer, int $page = 1, int $size = 20): array
    {
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT a.actor_id AS my_actor_id, a.action AS my_action, a.comment AS my_comment,'
            . ' a.acted_at AS my_acted_at, i.*'
            . ' FROM ' . PlatformStore::APPR_ACTION_TABLE . ' a'
            . ' INNER JOIN ' . PlatformStore::APPR_INSTANCE_TABLE . ' i ON i.id = a.instance_id'
            . " WHERE a.action IN ('approve', 'reject')"
            . ' AND a.actor_id IN (' . $this->identityLiterals($viewer) . ')'
            . ' ORDER BY a.acted_at DESC, a.id DESC'
        );

        $matched = [];
        foreach ($rows as $row) {
            $no = (string) ($row['approval_no'] ?? '');
            if ($no === '' || isset($matched[$no])) {
                continue;
            }
            $item = $this->decorateBrief($row);
            $item['my_action'] = (string) $row['my_action'];
            $item['my_comment'] = (string) $row['my_comment'];
            $item['my_acted_at'] = (string) $row['my_acted_at'];
            $matched[$no] = $item;
        }

        $page = max(1, $page);
        $size = max(1, min(200, $size));
        $total = count($matched);
        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'items' => array_slice(array_values($matched), ($page - 1) * $size, $size),
        ];
    }

    /** 当前用户能否对该单据执行审批（审批中心详情页按钮置灰依据）。 */
    public function canAct(Identity $viewer, string $approvalNo): bool
    {
        $instance = $this->instanceByNo(trim($approvalNo));
        if ($instance === null) {
            return false;
        }
        if ((string) ($instance['status'] ?? '') !== 'pending' || (int) ($instance['orphaned'] ?? 0) !== 0) {
            return false;
        }
        return $this->matchActiveNode((int) $instance['id'], $viewer) !== null;
    }

    /** 卸载期：该包 pending 单据冻结为孤儿态（数据保留、不再推进）。返回影响条数。 */
    public function orphanPackage(string $short): int
    {
        $short = $this->assertPackageName($short);
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::APPR_INSTANCE_TABLE
            . ' WHERE package=' . $this->store->quote($short)
            . " AND status='pending' AND orphaned=0"
        );
        $n = (int) ($rows[0]['c'] ?? 0);
        if ($n > 0) {
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_INSTANCE_TABLE . ' SET orphaned=1'
                . ' WHERE package=' . $this->store->quote($short)
                . " AND status='pending' AND orphaned=0"
            );
            $this->journal('orphan', ['package' => $short, 'count' => $n]);
        }
        return $n;
    }

    /** 重装/升级期：接管该包孤儿单据，并重新解析在途节点审批人快照。返回接管条数。 */
    public function adoptPackage(string $short): int
    {
        $short = $this->assertPackageName($short);
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT * FROM ' . PlatformStore::APPR_INSTANCE_TABLE
            . ' WHERE package=' . $this->store->quote($short)
            . " AND status='pending' AND orphaned=1"
        );
        $n = count($rows);
        if ($n === 0) {
            return 0;
        }

        $this->resetQueues();
        foreach ($rows as $instance) {
            try {
                $this->reresolveActiveNodes($instance);
            } catch (\Throwable $e) {
                // 单个单据解析失败不阻断安装流程，节点已标 error 可见
                $this->journal('adopt_resolve_failed', [
                    'package' => $short,
                    'approval_no' => (string) ($instance['approval_no'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $db->exec(
            'UPDATE ' . PlatformStore::APPR_INSTANCE_TABLE . ' SET orphaned=0'
            . ' WHERE package=' . $this->store->quote($short)
            . " AND status='pending' AND orphaned=1"
        );
        $this->flushNotifications();
        $this->journal('adopt', ['package' => $short, 'count' => $n]);
        return $n;
    }

    // —— 引擎：节点推进 ——

    /**
     * 从 $nodeId 开始推进流程（到达即执行）。
     *
     * @param array $definition 规范化后的流程定义
     * @param list<string> $branchPath 分支路径（如 ['gw2#0']）
     * @param array $ctx 当前位置的并行分支上下文 {gateway,branch,join,parent}
     * @param array $submitCtx 提交上下文（payload/submitter，供条件与解析器使用）
     */
    private function runNode(int $instanceId, array $definition, ?string $nodeId, array $branchPath, array $ctx, array $submitCtx): void
    {
        $guard = 0;
        while ($nodeId !== null && $nodeId !== '') {
            if (++$guard > self::MAX_GRAPH_WALK) {
                throw new ApprovalFlowInvalid('流程节点推进超出上限，疑似流程定义存在环');
            }

            // 虚拟汇合标识：__join_<网关id>__
            if ($this->isJoinToken($nodeId)) {
                $this->arriveAtJoin($instanceId, $definition, (string) $this->joinGatewayId($nodeId), $branchPath, $ctx, $submitCtx);
                return;
            }

            // 分支直接指向汇合节点
            if (($ctx['join'] ?? null) !== null && $nodeId === $ctx['join']) {
                $this->arriveAtJoin($instanceId, $definition, (string) ($ctx['gateway'] ?? ''), $branchPath, $ctx, $submitCtx);
                return;
            }

            $node = $definition['nodes'][$nodeId] ?? null;
            if ($node === null) {
                throw new ApprovalFlowInvalid("节点不存在：{$nodeId}");
            }

            $type = (string) $node['type'];
            if ($type === 'approval') {
                $this->activateApprovalNode($instanceId, $node, $branchPath, $ctx, $submitCtx);
                return;
            }

            if ($type === 'exclusive') {
                [$index, $next] = $this->pickExclusiveBranch($node, $submitCtx['payload'], $submitCtx);
                if ($next === null) {
                    return;
                }
                $branchPath[] = $nodeId . '#' . (string) $index;
                $nodeId = $next;
                continue;
            }

            // parallel：各分支并行推进（都走同一实例，汇合由 join 标记行计数）
            $join = (string) ($node['join'] ?? '');
            foreach ($node['branches'] as $index => $branch) {
                $childPath = $branchPath;
                $childPath[] = $nodeId . '#' . (string) $index;
                $childCtx = [
                    'gateway' => $nodeId,
                    'branch' => (string) $index,
                    'join' => $join,
                    'parent' => $ctx,
                ];
                $this->runNode($instanceId, $definition, (string) $branch['next'], $childPath, $childCtx, $submitCtx);
            }
            return;
        }
    }

    /**
     * 汇合点到达：写 join 标记行，按 distinct 分支数判定是否进入 join 后继节点。
     */
    private function arriveAtJoin(int $instanceId, array $definition, string $gatewayId, array $branchPath, array $ctx, array $submitCtx): void
    {
        $gateway = $definition['nodes'][$gatewayId] ?? null;
        if ($gateway === null || (string) $gateway['type'] !== 'parallel') {
            throw new ApprovalFlowInvalid("汇合标识指向的并行网关不存在：{$gatewayId}");
        }
        $joinNode = (string) ($gateway['join'] ?? '');
        $need = count($gateway['branches']);
        $branch = (string) ($ctx['branch'] ?? '');
        $now = date('c');

        $this->insertNode($instanceId, $joinNode, 'join', 'any', [], [], 'approved', $branchPath, [
            'gateway' => $gatewayId,
            'branch' => $branch,
            'arrived' => true,
        ], $now, $now);

        $rows = $this->store->db()->query(
            'SELECT id, join_ctx FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId
            . " AND node_type='join' AND status='approved'"
        );
        $branches = [];
        $targets = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['join_ctx'] ?? ''), true);
            if (!is_array($meta) || (string) ($meta['gateway'] ?? '') !== $gatewayId) {
                continue;
            }
            if (!(bool) ($meta['arrived'] ?? false)) {
                continue;
            }
            $branches[(string) ($meta['branch'] ?? '')] = true;
            $targets[] = (int) $row['id'];
        }

        if (count($branches) < $need || $targets === []) {
            return;
        }

        // 汇合完成：本次到达的标记行置 arrived=false，避免重复激活
        $db = $this->store->db();
        foreach ($targets as $id) {
            $db->exec(
                'UPDATE ' . PlatformStore::APPR_NODE_TABLE
                . ' SET join_ctx=' . $this->store->quote((string) json_encode([
                    'gateway' => $gatewayId,
                    'branch' => '',
                    'arrived' => false,
                ]))
                . ' WHERE id=' . $id
            );
        }

        $parentPath = array_values(array_filter(
            $branchPath,
            static fn ($p): bool => !str_starts_with((string) $p, $gatewayId . '#')
        ));
        $parentCtx = is_array($ctx['parent'] ?? null) ? $ctx['parent'] : $this->rootCtx();
        $this->runNode($instanceId, $definition, $joinNode, $parentPath, $parentCtx, $submitCtx);
    }

    /** 激活审批节点：解析审批人快照落库，提醒快照内全部用户。 */
    private function activateApprovalNode(int $instanceId, array $node, array $branchPath, array $ctx, array $submitCtx): void
    {
        $nodeId = (string) $node['id'];
        $source = $node['approvers'];
        $now = date('c');

        try {
            $snapshot = $this->resolveSnapshot($source, [
                'package' => $submitCtx['package'],
                'flow_key' => $submitCtx['flow_key'],
                'payload' => $submitCtx['payload'],
                'submitter_id' => $submitCtx['submitter_id'],
                'submitter_name' => $submitCtx['submitter_name'],
            ]);
        } catch (\Throwable $e) {
            $this->resolverErrors[] = "节点 {$nodeId}：" . $e->getMessage();
            $this->insertNode($instanceId, $nodeId, 'approval', (string) $node['mode'], [], $source, 'error', $branchPath, $ctx, $now, '');
            $this->journal('resolver_error', [
                'approval_no' => $submitCtx['approval_no'] ?? '',
                'node_id' => $nodeId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($snapshot === []) {
            $this->resolverErrors[] = "节点 {$nodeId}：审批人解析结果为空";
            $this->insertNode($instanceId, $nodeId, 'approval', (string) $node['mode'], [], $source, 'error', $branchPath, $ctx, $now, '');
            return;
        }

        $nodeInstanceId = $this->insertNode(
            $instanceId,
            $nodeId,
            'approval',
            (string) $node['mode'],
            $snapshot,
            $source,
            'active',
            $branchPath,
            $ctx,
            $now,
            ''
        );

        if ($this->collectActivated) {
            $this->activatedSnapshots[] = [
                'node_id' => $nodeId,
                'node_instance_id' => $nodeInstanceId,
                'approvers' => $snapshot,
            ];
        }

        $approvalNo = (string) ($submitCtx['approval_no'] ?? '');
        $this->queueInbox((string) $submitCtx['package'], $snapshot, [
            'title' => '待办提醒：' . (string) $submitCtx['title'],
            'content' => '提交人：' . ((string) $submitCtx['submitter_name'] !== '' ? (string) $submitCtx['submitter_name'] : (string) $submitCtx['submitter_id'])
                . '；节点：' . ((string) ($node['name'] ?? '') !== '' ? (string) $node['name'] : $nodeId),
            'link' => '/admin/ApprovalAdmin/detail?no=' . $approvalNo,
        ], [
            'sender_id' => 'platform',
            'sender_name' => '审批中心',
        ]);
    }

    /** 节点通过后继续推进：读节点行的 branch_path / join_ctx 复原位置。 */
    private function stepFromNode(array $instance, array $nodeRow): void
    {
        $flowRow = $this->flowRow((string) $instance['package'], (string) $instance['flow_key']);
        $definition = $flowRow === null
            ? null
            : $this->decodeFlowDefinition((string) ($flowRow['definition'] ?? ''));
        if ($definition === null) {
            throw new ApprovalFlowInvalid('流程定义无法解析：' . (string) $instance['flow_key']);
        }
        $node = $definition['nodes'][(string) $nodeRow['node_id']] ?? null;
        if ($node === null || (string) $node['type'] !== 'approval') {
            return;
        }
        $next = $node['next'] ?? null;
        if ($next === null || (string) $next === '') {
            return;
        }

        $branchPath = $this->decodeList((string) ($nodeRow['branch_path'] ?? ''));
        $ctx = $this->decodeCtx((string) ($nodeRow['join_ctx'] ?? ''));
        $payload = json_decode((string) $instance['payload'], true);
        $submitCtx = [
            'package' => (string) $instance['package'],
            'flow_key' => (string) $instance['flow_key'],
            'title' => (string) $instance['title'],
            'payload' => is_array($payload) ? $payload : [],
            'submitter_id' => (string) $instance['submitter_id'],
            'submitter_name' => (string) $instance['submitter_name'],
            'approval_no' => (string) $instance['approval_no'],
        ];

        $db = $this->store->db();
        try {
            $db->begin();
            $this->runNode((int) $instance['id'], $definition, (string) $next, $branchPath, $ctx, $submitCtx);
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // 回滚失败不掩盖原始异常
            }
            throw $e;
        }
    }

    /** 单据终态判定：pending + 未冻结 + 无 active/waiting/error 节点 → approved。 */
    private function finalizeInstance(int $instanceId): void
    {
        $db = $this->store->db();
        $rows = $db->query(
            'SELECT status FROM ' . PlatformStore::APPR_NODE_TABLE . ' WHERE instance_id=' . $instanceId
            . " AND node_type != 'join' AND status IN ('active', 'waiting', 'error')"
        );
        if ($rows !== []) {
            return;
        }
        $all = $db->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId . " AND node_type='approval'"
        );
        if ((int) ($all[0]['c'] ?? 0) === 0) {
            return;
        }

        $instance = $this->instanceById($instanceId);
        if ($instance === null || (string) $instance['status'] !== 'pending' || (int) $instance['orphaned'] !== 0) {
            return;
        }

        $now = date('c');
        $db->exec(
            'UPDATE ' . PlatformStore::APPR_INSTANCE_TABLE . " SET status='approved', finished_at="
            . $this->store->quote($now) . ' WHERE id=' . $instanceId . " AND status='pending'"
        );
        $this->queueEvent(self::EVENT_COMPLETED, [
            'approval_no' => (string) $instance['approval_no'],
            'package' => (string) $instance['package'],
            'status' => 'approved',
            'finished_at' => $now,
        ]);
        $this->queueSubmitterNotice($instance, 'approved', '');
    }

    /** 接管时重解析：按 approver_source 重新计算 active 节点快照。 */
    private function reresolveActiveNodes(array $instance): void
    {
        $instanceId = (int) $instance['id'];
        $flowRow = $this->flowRow((string) $instance['package'], (string) $instance['flow_key']);
        $definition = $flowRow === null
            ? null
            : $this->decodeFlowDefinition((string) ($flowRow['definition'] ?? ''));
        $payload = json_decode((string) $instance['payload'], true);
        $submitCtx = [
            'package' => (string) $instance['package'],
            'flow_key' => (string) $instance['flow_key'],
            'title' => (string) $instance['title'],
            'payload' => is_array($payload) ? $payload : [],
            'submitter_id' => (string) $instance['submitter_id'],
            'submitter_name' => (string) $instance['submitter_name'],
            'approval_no' => (string) $instance['approval_no'],
        ];

        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId
            . " AND node_type='approval' AND status IN ('active', 'error')"
        );
        $db = $this->store->db();
        foreach ($rows as $row) {
            $nodeId = (string) $row['node_id'];
            $node = is_array($definition) ? ($definition['nodes'][$nodeId] ?? null) : null;
            $source = $node !== null && (string) $node['type'] === 'approval'
                ? $node['approvers']
                : (json_decode((string) ($row['approver_source'] ?? ''), true) ?: null);
            if (!is_array($source)) {
                continue;
            }
            try {
                $snapshot = $this->resolveSnapshot($source, [
                    'package' => $submitCtx['package'],
                    'flow_key' => $submitCtx['flow_key'],
                    'payload' => $submitCtx['payload'],
                    'submitter_id' => $submitCtx['submitter_id'],
                    'submitter_name' => $submitCtx['submitter_name'],
                ]);
                if ($snapshot === []) {
                    throw new ApprovalResolverError('审批人解析结果为空');
                }
                $db->exec(
                    'UPDATE ' . PlatformStore::APPR_NODE_TABLE
                    . ' SET approver_snapshot=' . $this->store->quote((string) json_encode($snapshot))
                    . ", status='active', activated_at=" . $this->store->quote(date('c'))
                    . ' WHERE id=' . (int) $row['id']
                );
                $this->queueInbox((string) $instance['package'], $snapshot, [
                    'title' => '待办提醒：' . (string) $instance['title'],
                    'content' => '功能包已重装，单据恢复推进；节点：' . $nodeId,
                    'link' => '/admin/ApprovalAdmin/detail?no=' . (string) $instance['approval_no'],
                ], ['sender_id' => 'platform', 'sender_name' => '审批中心']);
            } catch (\Throwable $e) {
                $db->exec(
                    'UPDATE ' . PlatformStore::APPR_NODE_TABLE
                    . " SET status='error', approver_snapshot='[]'"
                    . ' WHERE id=' . (int) $row['id']
                );
                $this->resolverErrors[] = "节点 {$nodeId}：" . $e->getMessage();
            }
        }
    }

    // —— 审批人解析 ——

    /**
     * 解析审批人来源为规范用户 id 快照（合并去重）。
     *
     * @return list<string>
     */
    private function resolveSnapshot(array $source, array $context): array
    {
        $ids = [];
        foreach ((array) ($source['users'] ?? []) as $raw) {
            $canonical = $this->canonicalUserId((string) $raw);
            if ($canonical !== '') {
                $ids[] = $canonical;
            }
        }
        $roles = array_values(array_filter(array_map('strval', (array) ($source['roles'] ?? []))));
        if ($roles !== []) {
            foreach ($this->usersWithRoles($roles) as $userId) {
                $ids[] = $userId;
            }
        }
        $resolver = trim((string) ($source['resolver'] ?? ''));
        if ($resolver !== '') {
            $handler = self::$resolvers[$resolver] ?? '';
            if ($handler === '') {
                throw new ApprovalResolverError("解析器未注册：{$resolver}");
            }
            if (!class_exists($handler)) {
                throw new ApprovalResolverError("解析器类不存在：{$handler}");
            }
            try {
                $instance = new $handler();
                if (!is_object($instance) || !method_exists($instance, 'resolve')) {
                    throw new ApprovalResolverError("解析器 {$resolver} 缺少 resolve 方法");
                }
                $resolved = $instance->resolve($context);
            } catch (ApprovalResolverError $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ApprovalResolverError("解析器 {$resolver} 执行失败：" . $e->getMessage());
            }
            if (!is_array($resolved)) {
                throw new ApprovalResolverError("解析器 {$resolver} 返回值须为用户 id 数组");
            }
            foreach ($resolved as $raw) {
                $canonical = $this->canonicalUserId((string) $raw);
                if ($canonical !== '') {
                    $ids[] = $canonical;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));
        sort($ids);
        return $ids;
    }

    /** 拥有指定角色的本地用户 id 列表。 */
    private function usersWithRoles(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('strval', $roleIds), static fn (string $v): bool => trim($v) !== '')));
        if ($roleIds === []) {
            return [];
        }
        $rbac = $this->rbac();
        if ($rbac === null) {
            return [];
        }
        $want = array_flip($roleIds);
        $out = [];
        foreach ($rbac->section(RbacStorage::USER_ROLES) as $userId => $owned) {
            if (array_intersect_key($want, array_flip(array_map('strval', (array) $owned))) !== []) {
                $out[] = (string) $userId;
            }
        }
        return $out;
    }

    /** 外部标识归一为本地用户 id（无档案时保留原值）。 */
    private function canonicalUserId(string $rawId): string
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return '';
        }
        $users = $this->users();
        if (isset($users[$rawId])) {
            return (string) ($users[$rawId]['id'] ?? $rawId);
        }
        foreach ($users as $userId => $user) {
            if ((string) ($user['ref_id'] ?? '') === $rawId) {
                return (string) $userId;
            }
        }
        return $rawId;
    }

    /** 身份可能对应的全部字面量（本地 id / ref_id 双向）。 */
    private function identityCandidates(string $rawId): array
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return [];
        }
        $ids = [$rawId];
        $canonical = $this->canonicalUserId($rawId);
        if ($canonical !== '') {
            $ids[] = $canonical;
        }
        $users = $this->users();
        if (isset($users[$rawId]) && (string) ($users[$rawId]['ref_id'] ?? '') !== '') {
            $ids[] = (string) $users[$rawId]['ref_id'];
        }
        return array_values(array_unique($ids));
    }

    private function identityInSnapshot(Identity $identity, array $snapshot): bool
    {
        if ($snapshot === []) {
            return false;
        }
        $normalized = array_map('strval', $snapshot);
        return array_intersect($this->identityCandidates($identity->id), $normalized) !== [];
    }

    /** SQL IN 字面量（actor_id 匹配用）。 */
    private function identityLiterals(Identity $identity): string
    {
        $ids = $this->identityCandidates($identity->id);
        if ($ids === []) {
            $ids = [$identity->id];
        }
        return implode(',', array_map(fn (string $id): string => $this->store->quote($id), $ids));
    }

    // —— 动作与匹配 ——

    private function matchActiveNode(int $instanceId, Identity $identity): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId
            . " AND status='active' AND node_type='approval'"
            . ' ORDER BY activated_at ASC, id ASC'
        );
        foreach ($rows as $row) {
            if ($this->identityInSnapshot($identity, $this->decodeList((string) ($row['approver_snapshot'] ?? '')))) {
                return $row;
            }
        }
        return null;
    }

    private function alreadyActed(int $nodeInstanceId, Identity $identity, string $action): bool
    {
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::APPR_ACTION_TABLE
            . ' WHERE node_instance_id=' . $nodeInstanceId
            . ' AND action=' . $this->store->quote($action)
            . ' AND actor_id IN (' . $this->identityLiterals($identity) . ')'
        );
        return (int) ($rows[0]['c'] ?? 0) > 0;
    }

    /** 该人对该单据是否已有指定动作（幂等重试判定）。 */
    private function hadAction(int $instanceId, Identity $identity, string $action): bool
    {
        $rows = $this->store->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::APPR_ACTION_TABLE
            . ' WHERE instance_id=' . $instanceId
            . ' AND action=' . $this->store->quote($action)
            . ' AND actor_id IN (' . $this->identityLiterals($identity) . ')'
        );
        return (int) ($rows[0]['c'] ?? 0) > 0;
    }

    /** 会签判定：快照内每个人在审批人集合中均已 approve。 */
    private function allApproved(int $nodeInstanceId, array $snapshot): bool
    {
        if ($snapshot === []) {
            return false;
        }
        $rows = $this->store->db()->query(
            'SELECT actor_id FROM ' . PlatformStore::APPR_ACTION_TABLE
            . ' WHERE node_instance_id=' . $nodeInstanceId . " AND action='approve'"
        );
        $approved = [];
        foreach ($rows as $row) {
            $approved[] = $this->canonicalUserId((string) $row['actor_id']);
        }
        $approved = array_values(array_unique($approved));
        foreach ($snapshot as $userId) {
            if (!in_array($this->canonicalUserId((string) $userId), $approved, true)) {
                return false;
            }
        }
        return true;
    }

    private function insertAction(int $instanceId, int $nodeInstanceId, string $actorId, string $actorName, string $action, string $comment, string $actedAt): void
    {
        $this->store->db()->exec(
            'INSERT INTO ' . PlatformStore::APPR_ACTION_TABLE
            . ' (instance_id, node_instance_id, actor_id, actor_name, action, comment, acted_at) VALUES ('
            . $instanceId . ', '
            . $nodeInstanceId . ', '
            . $this->store->quote($this->textSlice($actorId, 191)) . ', '
            . $this->store->quote($this->textSlice($actorName, 191)) . ', '
            . $this->store->quote($action) . ', '
            . $this->store->quote($comment) . ', '
            . $this->store->quote($actedAt) . ')'
        );
    }

    private function insertNode(
        int $instanceId,
        string $nodeId,
        string $nodeType,
        string $mode,
        array $snapshot,
        array $source,
        string $status,
        array $branchPath,
        ?array $ctx,
        string $activatedAt,
        string $finishedAt
    ): int {
        $db = $this->store->db();
        $db->exec(
            'INSERT INTO ' . PlatformStore::APPR_NODE_TABLE
            . ' (instance_id, node_id, node_type, mode, approver_snapshot, approver_source, status,'
            . ' branch_path, join_ctx, activated_at, finished_at) VALUES ('
            . $instanceId . ', '
            . $this->store->quote($nodeId) . ', '
            . $this->store->quote($nodeType) . ', '
            . $this->store->quote($mode) . ', '
            . $this->store->quote((string) json_encode($snapshot)) . ', '
            . $this->store->quote((string) json_encode($source)) . ', '
            . $this->store->quote($status) . ', '
            . $this->store->quote((string) json_encode($branchPath)) . ', '
            . $this->store->quote($ctx === null ? '' : (string) json_encode($ctx)) . ', '
            . $this->store->quote($activatedAt) . ', '
            . $this->store->quote($finishedAt) . ')'
        );
        $rows = $db->query(
            'SELECT id FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId . ' ORDER BY id DESC LIMIT 1'
        );
        return (int) ($rows[0]['id'] ?? 0);
    }

    // —— 通知队列 ——

    private function queueEvent(string $topic, array $payload): void
    {
        $this->queuedEvents[] = ['topic' => $topic, 'payload' => $payload];
    }

    private function queueInbox(string $package, array $userIds, array $message, array $meta): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds), static fn (string $v): bool => $v !== '')));
        if ($userIds === []) {
            return;
        }
        $this->queuedInbox[] = [
            'package' => $package,
            'message' => $message,
            'userIds' => $userIds,
            'meta' => $meta,
        ];
    }

    /** 单据终态通知提交人。 */
    private function queueSubmitterNotice(array $instance, string $status, string $comment): void
    {
        $label = match ($status) {
            'approved' => '已通过',
            'rejected' => '已驳回',
            'cancelled' => '已撤回',
            default => $status,
        };
        $content = '单据 ' . (string) $instance['approval_no'] . ' ' . $label;
        if (trim($comment) !== '') {
            $content .= '；意见：' . $this->textSlice(trim($comment), 200);
        }
        $this->queueInbox((string) $instance['package'], [(string) $instance['submitter_id']], [
            'title' => '审批结果：' . (string) $instance['title'],
            'content' => $content,
            'link' => '/admin/ApprovalAdmin/detail?no=' . (string) $instance['approval_no'],
        ], ['sender_id' => 'platform', 'sender_name' => '审批中心']);
    }

    /** 事务提交后发送：单条通知失败只落日志，不影响主流程。 */
    private function flushNotifications(): void
    {
        $events = $this->queuedEvents;
        $inbox = $this->queuedInbox;
        $this->resetQueues();

        foreach ($events as $event) {
            try {
                (new MessagingService())->publish($event['topic'], $event['payload']);
            } catch (\Throwable $e) {
                $this->journal('event_failed', ['topic' => $event['topic'], 'error' => $e->getMessage()]);
            }
        }
        foreach ($inbox as $item) {
            try {
                (new InboxService())->sendToUsers($item['package'], $item['message'], $item['userIds'], $item['meta']);
            } catch (\Throwable $e) {
                $this->journal('inbox_failed', ['package' => $item['package'], 'error' => $e->getMessage()]);
            }
        }
    }

    private function resetQueues(): void
    {
        $this->queuedEvents = [];
        $this->queuedInbox = [];
        $this->resolverErrors = [];
    }

    // —— 流程定义：规范化与校验 ——

    private function normalizeFlow(array $flow): array
    {
        $flowKey = trim((string) ($flow['flow_key'] ?? ''));
        if ($flowKey === '' || strlen($flowKey) > 191 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $flowKey)) {
            throw new ApprovalFlowInvalid('flow_key 须为非空、不超过 191 字符且只含字母数字点横线下划线的标识');
        }
        $name = $this->textSlice(trim((string) ($flow['name'] ?? '')), 255);
        $entry = trim((string) ($flow['entry'] ?? ''));
        $rawNodes = $flow['nodes'] ?? null;
        if (!is_array($rawNodes) || $rawNodes === []) {
            throw new ApprovalFlowInvalid('nodes 须为非空数组');
        }

        $nodes = [];
        foreach ($rawNodes as $raw) {
            if (!is_array($raw)) {
                throw new ApprovalFlowInvalid('节点定义须为对象');
            }
            $id = trim((string) ($raw['id'] ?? ''));
            if ($id === '' || strlen($id) > 191) {
                throw new ApprovalFlowInvalid('节点 id 须为非空且不超过 191 字符');
            }
            if (isset($nodes[$id])) {
                throw new ApprovalFlowInvalid("节点 {$id}：id 重复");
            }
            $type = (string) ($raw['type'] ?? 'approval');
            if (!in_array($type, ['approval', 'exclusive', 'parallel'], true)) {
                throw new ApprovalFlowInvalid("节点 {$id}：type 只能是 approval / exclusive / parallel");
            }

            $node = [
                'id' => $id,
                'type' => $type,
                'name' => $this->textSlice(trim((string) ($raw['name'] ?? '')), 255),
            ];

            if ($type === 'approval') {
                $ap = is_array($raw['approvers'] ?? null) ? $raw['approvers'] : [];
                $users = array_values(array_filter(
                    array_map(static fn ($v): string => trim((string) $v), (array) ($ap['users'] ?? [])),
                    static fn (string $v): bool => $v !== ''
                ));
                $roles = array_values(array_filter(
                    array_map(static fn ($v): string => trim((string) $v), (array) ($ap['roles'] ?? [])),
                    static fn (string $v): bool => $v !== ''
                ));
                $resolver = trim((string) ($ap['resolver'] ?? ''));
                if ($users === [] && $roles === [] && $resolver === '') {
                    throw new ApprovalFlowInvalid("节点 {$id}：approvers 至少需提供 users / roles / resolver 之一");
                }
                $mode = (string) ($raw['mode'] ?? 'any');
                if (!in_array($mode, ['any', 'all'], true)) {
                    throw new ApprovalFlowInvalid("节点 {$id}：mode 只能是 any（或签）或 all（会签）");
                }
                $node['approvers'] = ['users' => $users, 'roles' => $roles, 'resolver' => $resolver];
                $node['mode'] = $mode;
                $node['next'] = $this->normalizeNext($raw['next'] ?? null, $id);
            } elseif ($type === 'exclusive') {
                $branches = [];
                $defaults = 0;
                foreach ((array) ($raw['branches'] ?? []) as $branch) {
                    if (!is_array($branch)) {
                        throw new ApprovalFlowInvalid("节点 {$id}：分支定义须为对象");
                    }
                    $condition = $branch['condition'] ?? null;
                    if ($condition === null) {
                        $defaults++;
                    } else {
                        $this->validateCondition($condition, 1, $id);
                    }
                    $next = trim((string) ($branch['next'] ?? ''));
                    if ($next === '') {
                        throw new ApprovalFlowInvalid("节点 {$id}：分支缺少 next");
                    }
                    $branches[] = ['condition' => $condition, 'next' => $next];
                }
                if ($branches === []) {
                    throw new ApprovalFlowInvalid("节点 {$id}：branches 不能为空");
                }
                if ($defaults === 0) {
                    throw new ApprovalFlowInvalid("节点 {$id}：排他网关必须包含一个 condition 为 null 的默认分支");
                }
                if ($defaults > 1) {
                    throw new ApprovalFlowInvalid("节点 {$id}：默认分支（condition=null）只能有一个");
                }
                $node['branches'] = $branches;
            } else {
                $branches = [];
                foreach ((array) ($raw['branches'] ?? []) as $branch) {
                    if (!is_array($branch)) {
                        throw new ApprovalFlowInvalid("节点 {$id}：分支定义须为对象");
                    }
                    $next = trim((string) ($branch['next'] ?? ''));
                    if ($next === '') {
                        throw new ApprovalFlowInvalid("节点 {$id}：分支缺少 next");
                    }
                    $branches[] = ['next' => $next];
                }
                if (count($branches) < 2) {
                    throw new ApprovalFlowInvalid("节点 {$id}：并行网关 branches 至少 2 支");
                }
                $join = trim((string) ($raw['join'] ?? ''));
                if ($join === '') {
                    throw new ApprovalFlowInvalid("节点 {$id}：并行网关必须声明 join（汇合后继节点 id）");
                }
                $node['branches'] = $branches;
                $node['join'] = $join;
            }

            $nodes[$id] = $node;
        }

        if ($entry === '') {
            throw new ApprovalFlowInvalid('entry 不能为空');
        }
        if (!isset($nodes[$entry])) {
            throw new ApprovalFlowInvalid("entry 节点 {$entry} 不存在");
        }

        return ['flow_key' => $flowKey, 'name' => $name, 'entry' => $entry, 'nodes' => $nodes];
    }

    private function normalizeNext(mixed $next, string $nodeId): ?string
    {
        if ($next === null) {
            return null;
        }
        $value = trim((string) $next);
        if ($value === '') {
            return null;
        }
        if ($this->isJoinToken($value) && $this->joinGatewayId($value) === '') {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：next 的汇合标识格式应为 __join_<并行网关id>__");
        }
        return $value;
    }

    /** 引用完整性 + 环检测 + 并行分支可达汇合点。 */
    private function validateFlow(array $definition): void
    {
        $nodes = $definition['nodes'];
        foreach ($nodes as $id => $node) {
            $id = (string) $id;
            if ((string) $node['type'] === 'approval') {
                $this->assertReference($id, (string) ($node['next'] ?? ''), $nodes);
            } elseif ((string) $node['type'] === 'exclusive') {
                foreach ($node['branches'] as $branch) {
                    $this->assertReference($id, (string) $branch['next'], $nodes);
                }
            } else {
                $join = (string) $node['join'];
                if ($join === $id) {
                    throw new ApprovalFlowInvalid("节点 {$id}：并行网关的 join 不能指向自身");
                }
                if (!isset($nodes[$join])) {
                    throw new ApprovalFlowInvalid("节点 {$id}：join 节点 {$join} 不存在");
                }
                foreach ($node['branches'] as $index => $branch) {
                    $this->assertReference($id, (string) $branch['next'], $nodes);
                    if (!$this->reachesJoin((string) $branch['next'], $join, $id, $nodes, [])) {
                        throw new ApprovalFlowInvalid(
                            "节点 {$id}：第 " . ((int) $index + 1) . ' 支路径未到达汇合节点 ' . $join
                        );
                    }
                }
            }
        }
        $this->assertNoCycle($nodes);
    }

    private function assertReference(string $nodeId, string $next, array $nodes): void
    {
        if ($next === '') {
            return;
        }
        if ($this->isJoinToken($next)) {
            $gatewayId = $this->joinGatewayId($next);
            if ($gatewayId === '') {
                throw new ApprovalFlowInvalid("节点 {$nodeId}：汇合标识格式应为 __join_<并行网关id>__");
            }
            $gateway = $nodes[$gatewayId] ?? null;
            if ($gateway === null) {
                throw new ApprovalFlowInvalid("节点 {$nodeId}：汇合标识指向的并行网关 {$gatewayId} 不存在");
            }
            if ((string) $gateway['type'] !== 'parallel') {
                throw new ApprovalFlowInvalid("节点 {$nodeId}：汇合标识只能指向并行网关（{$gatewayId}）");
            }
            return;
        }
        if (!isset($nodes[$next])) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：next 指向的节点 {$next} 不存在");
        }
    }

    /** 分支路径是否可达汇合节点（跟随后继边，含汇合标识重定向）。 */
    private function reachesJoin(string $start, string $join, string $gatewayId, array $nodes, array $visited): bool
    {
        if ($start === '') {
            return false;
        }
        if ($start === $join) {
            return true;
        }
        if ($this->isJoinToken($start)) {
            return $this->joinGatewayId($start) === $gatewayId;
        }
        if (isset($visited[$start]) || count($visited) > self::MAX_GRAPH_WALK) {
            return false;
        }
        $visited[$start] = true;
        foreach ($this->successors($start, $nodes) as $next) {
            if ($this->reachesJoin($next, $join, $gatewayId, $nodes, $visited)) {
                return true;
            }
        }
        return false;
    }

    /** 三色 DFS 环检测（汇合标识重定向到对应并行网关的 join 节点）。 */
    private function assertNoCycle(array $nodes): void
    {
        $state = [];
        foreach (array_keys($nodes) as $id) {
            $id = (string) $id;
            if (($state[$id] ?? 0) === 0) {
                $this->walkCycle($id, $nodes, $state);
            }
        }
    }

    private function walkCycle(string $id, array $nodes, array &$state): void
    {
        $state[$id] = 1;
        foreach ($this->successors($id, $nodes) as $next) {
            $mark = $state[$next] ?? 0;
            if ($mark === 1) {
                throw new ApprovalFlowInvalid("流程存在环：节点 {$id} → {$next} 回到已访问节点");
            }
            if ($mark === 0) {
                $this->walkCycle($next, $nodes, $state);
            }
        }
        $state[$id] = 2;
    }

    /** 后继节点（汇合标识 → 对应并行网关的 join 节点）。 */
    private function successors(string $id, array $nodes): array
    {
        $node = $nodes[$id] ?? null;
        if ($node === null) {
            return [];
        }
        $raw = [];
        if ((string) $node['type'] === 'approval') {
            $raw[] = (string) ($node['next'] ?? '');
        } elseif ((string) $node['type'] === 'exclusive') {
            foreach ($node['branches'] as $branch) {
                $raw[] = (string) $branch['next'];
            }
        } else {
            foreach ($node['branches'] as $branch) {
                $raw[] = (string) $branch['next'];
            }
        }

        $out = [];
        foreach ($raw as $next) {
            if ($next === '') {
                continue;
            }
            if ($this->isJoinToken($next)) {
                $gateway = $nodes[$this->joinGatewayId($next)] ?? null;
                $target = is_array($gateway) ? (string) ($gateway['join'] ?? '') : '';
                if ($target !== '' && isset($nodes[$target])) {
                    $out[$target] = true;
                }
                continue;
            }
            if (isset($nodes[$next])) {
                $out[$next] = true;
            }
        }
        return array_keys($out);
    }

    private function validateCondition(mixed $condition, int $depth, string $nodeId): void
    {
        if ($depth > self::MAX_CONDITION_DEPTH) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：条件嵌套深度不能超过 " . self::MAX_CONDITION_DEPTH . ' 层');
        }
        if (!is_array($condition)) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：condition 须为对象");
        }
        foreach (['all', 'any'] as $group) {
            if (!array_key_exists($group, $condition)) {
                continue;
            }
            $list = $condition[$group];
            if (!is_array($list) || $list === []) {
                throw new ApprovalFlowInvalid("节点 {$nodeId}：条件 {$group} 须为非空数组");
            }
            foreach ($list as $sub) {
                $this->validateCondition($sub, $depth + 1, $nodeId);
            }
            return;
        }

        $field = trim((string) ($condition['field'] ?? ''));
        if ($field === '' || !preg_match('/^(payload|submitter)\.[A-Za-z0-9_.\-]+$/', $field)) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：条件 field 须为 payload.<路径> 或 submitter.<字段>");
        }
        $op = trim((string) ($condition['op'] ?? ''));
        if (!in_array($op, self::CONDITION_OPS, true)) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：条件 op 不支持（{$op}）");
        }
        if (($op === 'in' || $op === 'not_in') && !is_array($condition['value'] ?? null)) {
            throw new ApprovalFlowInvalid("节点 {$nodeId}：op {$op} 的 value 须为数组");
        }
    }

    // —— 条件求值 ——

    /** @return array{0:int|string|null,1:string|null} [分支下标, 目标节点] */
    private function pickExclusiveBranch(array $node, array $payload, array $submitCtx): array
    {
        $branches = $node['branches'];
        foreach ($branches as $index => $branch) {
            if ($branch['condition'] === null) {
                continue;
            }
            if ($this->evalCondition($branch['condition'], $payload, $submitCtx)) {
                return [$index, (string) $branch['next']];
            }
        }
        foreach ($branches as $index => $branch) {
            if ($branch['condition'] === null) {
                return [$index, (string) $branch['next']];
            }
        }
        return [null, null];
    }

    private function evalCondition(array $condition, array $payload, array $submitCtx): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach ((array) $condition['all'] as $sub) {
                if (!is_array($sub) || !$this->evalCondition($sub, $payload, $submitCtx)) {
                    return false;
                }
            }
            return true;
        }
        if (array_key_exists('any', $condition)) {
            foreach ((array) $condition['any'] as $sub) {
                if (is_array($sub) && $this->evalCondition($sub, $payload, $submitCtx)) {
                    return true;
                }
            }
            return false;
        }

        $field = (string) ($condition['field'] ?? '');
        $op = (string) ($condition['op'] ?? '');
        $submitterContext = [
            'id' => (string) ($submitCtx['submitter_id'] ?? ''),
            'username' => (string) ($submitCtx['submitter_name'] ?? ''),
            'name' => (string) ($submitCtx['submitter_name'] ?? ''),
            'tags' => [],
        ];
        $hit = $this->dig($field, $payload, $submitterContext);
        return $this->compareCondition($op, $hit, $condition['value'] ?? null);
    }

    /** @return array{found:bool,value:mixed} */
    private function dig(string $field, array $payload, array $submitter): array
    {
        $parts = explode('.', $field);
        $root = (string) array_shift($parts);
        $cursor = match ($root) {
            'payload' => $payload,
            'submitter' => $submitter,
            default => null,
        };
        if (!is_array($cursor)) {
            return ['found' => false, 'value' => null];
        }
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return ['found' => false, 'value' => null];
            }
            $cursor = $cursor[$part];
        }
        return ['found' => true, 'value' => $cursor];
    }

    private function compareCondition(string $op, array $hit, mixed $expected): bool
    {
        $found = (bool) $hit['found'];
        $actual = $hit['value'];

        if ($op === 'exists') {
            return $found;
        }
        if ($op === 'ne') {
            return !$found || !$this->looseEqual($actual, $expected);
        }
        if (!$found) {
            return false;
        }

        return match ($op) {
            'eq' => $this->looseEqual($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in' => is_array($expected) && $this->looseIn($actual, $expected),
            'not_in' => !is_array($expected) || !$this->looseIn($actual, $expected),
            'contains' => is_array($actual)
                ? $this->looseIn($expected, $actual)
                : (is_string($actual) && (string) $expected !== '' && str_contains($actual, (string) $expected)),
            default => false,
        };
    }

    private function looseEqual(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) || is_array($b) || $a === null || $b === null) {
            return $a === $b;
        }
        return (string) $a === (string) $b;
    }

    private function looseIn(mixed $needle, array $haystack): bool
    {
        foreach ($haystack as $item) {
            if ($this->looseEqual($needle, $item)) {
                return true;
            }
        }
        return false;
    }

    // —— 数据读取 ——

    private function flowRow(string $package, string $flowKey): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_FLOW_TABLE
            . ' WHERE package=' . $this->store->quote($package)
            . ' AND flow_key=' . $this->store->quote($flowKey)
            . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function instanceByNo(string $approvalNo): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_INSTANCE_TABLE
            . ' WHERE approval_no=' . $this->store->quote($approvalNo) . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function instanceById(int $instanceId): ?array
    {
        $rows = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_INSTANCE_TABLE . ' WHERE id=' . $instanceId . ' LIMIT 1'
        );
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function decodeFlowDefinition(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['nodes'], $data['entry'])) {
            return null;
        }
        if (!is_array($data['nodes']) || $data['nodes'] === []) {
            return null;
        }
        return $data;
    }

    // —— 装饰输出 ——

    private function decorateFlow(array $row): array
    {
        $definition = json_decode((string) ($row['definition'] ?? ''), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'package' => (string) ($row['package'] ?? ''),
            'flow_key' => (string) ($row['flow_key'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'definition' => is_array($definition) ? $definition : [],
            'version' => (int) ($row['version'] ?? 1),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function decorateBrief(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'approval_no' => (string) ($row['approval_no'] ?? ''),
            'package' => (string) ($row['package'] ?? ''),
            'flow_key' => (string) ($row['flow_key'] ?? ''),
            'flow_version' => (int) ($row['flow_version'] ?? 1),
            'title' => (string) ($row['title'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'submitter_id' => (string) ($row['submitter_id'] ?? ''),
            'submitter_name' => (string) ($row['submitter_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'orphaned' => (int) ($row['orphaned'] ?? 0),
            'payload_size' => (int) ($row['payload_size'] ?? 0),
            'submitted_at' => (string) ($row['submitted_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'finish_comment' => (string) ($row['finish_comment'] ?? ''),
        ];
    }

    private function decoratePendingRow(array $row): array
    {
        $item = $this->decorateBrief($row);
        $item['node_instance_id'] = (int) ($row['node_instance_id'] ?? 0);
        $item['node_id'] = (string) ($row['node_id'] ?? '');
        $item['node_mode'] = (string) ($row['mode'] ?? 'any');
        $item['node_activated_at'] = (string) ($row['node_activated_at'] ?? '');
        $activated = strtotime($item['node_activated_at']);
        $item['waiting_seconds'] = $activated === false ? 0 : max(0, time() - $activated);
        return $item;
    }

    private function decorateInstanceFull(array $row): array
    {
        $instanceId = (int) ($row['id'] ?? 0);
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $nodes = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_NODE_TABLE
            . ' WHERE instance_id=' . $instanceId . ' ORDER BY id ASC'
        );
        $actions = $this->store->db()->query(
            'SELECT * FROM ' . PlatformStore::APPR_ACTION_TABLE
            . ' WHERE instance_id=' . $instanceId . ' ORDER BY acted_at ASC, id ASC'
        );

        $nodeItems = [];
        $current = [];
        foreach ($nodes as $node) {
            if ((string) ($node['node_type'] ?? '') === 'join') {
                continue;
            }
            $item = [
                'id' => (int) ($node['id'] ?? 0),
                'node_id' => (string) ($node['node_id'] ?? ''),
                'node_type' => (string) ($node['node_type'] ?? ''),
                'mode' => (string) ($node['mode'] ?? ''),
                'approvers' => $this->decodeList((string) ($node['approver_snapshot'] ?? '')),
                'approver_source' => json_decode((string) ($node['approver_source'] ?? ''), true) ?: [],
                'status' => (string) ($node['status'] ?? ''),
                'branch_path' => $this->decodeList((string) ($node['branch_path'] ?? '')),
                'activated_at' => (string) ($node['activated_at'] ?? ''),
                'finished_at' => (string) ($node['finished_at'] ?? ''),
            ];
            if ($item['status'] === 'active') {
                $current[] = $item['node_id'];
            }
            $nodeItems[] = $item;
        }

        $detail = $this->decorateBrief($row);
        $detail['payload'] = is_array($payload) ? $payload : [];
        $detail['nodes'] = $nodeItems;
        $detail['actions'] = array_map(static fn (array $action): array => [
            'id' => (int) ($action['id'] ?? 0),
            'node_instance_id' => (int) ($action['node_instance_id'] ?? 0),
            'actor_id' => (string) ($action['actor_id'] ?? ''),
            'actor_name' => (string) ($action['actor_name'] ?? ''),
            'action' => (string) ($action['action'] ?? ''),
            'comment' => (string) ($action['comment'] ?? ''),
            'acted_at' => (string) ($action['acted_at'] ?? ''),
        ], $actions);
        $detail['current_nodes'] = $current;
        return $detail;
    }

    // —— 作用域与守卫 ——

    /**
     * 解析操作作用域：显式包名优先，否则取调用方包；$allowAll 时 host 可跨包。
     */
    private function resolveScope(?string $package, bool $allowAll = false): string
    {
        $caller = $this->callerFromTrace();
        $explicit = $package === null ? '' : trim($package);
        if ($explicit === '') {
            if ($allowAll && $caller === self::HOST_NAMESPACE) {
                return '';
            }
            return $caller;
        }
        $scope = $this->assertPackageName($explicit);
        if ($caller === self::HOST_NAMESPACE || $scope === $caller) {
            return $scope;
        }
        throw new ApprovalDenied("无权访问其他功能包的审批数据：{$scope}");
    }

    /** 单据级隔离：host 全放行，其余只允许本包单据。 */
    private function assertInstanceAccess(string $rowPackage): void
    {
        $caller = $this->callerFromTrace();
        if ($caller === self::HOST_NAMESPACE || $rowPackage === $caller) {
            return;
        }
        throw new ApprovalDenied("无权访问其他功能包的审批单：{$rowPackage}");
    }

    /** 显式包名与单据归属包一致性（防止用错包名越权读写）。 */
    private function assertExplicitPackage(?string $package, string $rowPackage): void
    {
        $explicit = $package === null ? '' : trim($package);
        if ($explicit === '') {
            return;
        }
        if ($this->assertPackageName($explicit) !== $rowPackage) {
            throw new ApprovalDenied("审批单归属包与指定包不一致：{$rowPackage}");
        }
    }

    private function assertOperable(array $instance): void
    {
        if ((string) ($instance['status'] ?? '') !== 'pending') {
            throw new ApprovalState(
                '单据已到终态（' . (string) ($instance['status'] ?? '') . '），不可再操作：'
                . (string) ($instance['approval_no'] ?? '')
            );
        }
        if ((int) ($instance['orphaned'] ?? 0) !== 0) {
            throw new ApprovalState(
                '单据所属功能包已卸载，当前处于冻结状态，不可操作：' . (string) ($instance['approval_no'] ?? '')
            );
        }
    }

    private function assertPackageName(string $package): string
    {
        $name = trim($package);
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) {
            throw new \InvalidArgumentException('功能包标识只能包含字母、数字、点、横线、下划线，且不超过 64 字符');
        }
        return $name;
    }

    /**
     * 回溯调用栈判定 caller 所属功能包：扫描 frame file 是否落在插件加载根下。
     * 命中即取路径首段短名；无法判定时返回 host。
     */
    private function callerFromTrace(): string
    {
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__, 6) . '/plugins'), '/') . '/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || strpos($file, $root) !== 0) {
                continue;
            }
            $short = explode('/', substr($file, strlen($root)), 2)[0];
            if ($short !== '' && preg_match('/^[A-Za-z0-9_.\-]+$/', $short)) {
                return $short;
            }
        }
        return self::HOST_NAMESPACE;
    }

    // —— 通用工具 ——

    private function rootCtx(): array
    {
        return ['gateway' => null, 'branch' => null, 'join' => null, 'parent' => null];
    }

    private function decodeList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_map('strval', $data));
    }

    private function decodeCtx(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $this->rootCtx();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->rootCtx();
        }
        $normalize = static function (mixed $v): ?string {
            if ($v === null) {
                return null;
            }
            $value = trim((string) $v);
            return $value === '' ? null : $value;
        };
        return [
            'gateway' => $normalize($data['gateway'] ?? null),
            'branch' => $normalize($data['branch'] ?? null),
            'join' => $normalize($data['join'] ?? null),
            'parent' => is_array($data['parent'] ?? null) ? $data['parent'] : null,
        ];
    }

    private function isJoinToken(string $value): bool
    {
        return str_starts_with($value, self::JOIN_PREFIX) && str_ends_with($value, self::JOIN_SUFFIX)
            && strlen($value) > strlen(self::JOIN_PREFIX) + strlen(self::JOIN_SUFFIX);
    }

    private function joinGatewayId(string $token): string
    {
        if (!$this->isJoinToken($token)) {
            return '';
        }
        return substr($token, strlen(self::JOIN_PREFIX), -strlen(self::JOIN_SUFFIX));
    }

    private function textSlice(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }

    private function textLen(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }

    private function users(): array
    {
        if ($this->usersCache !== null) {
            return $this->usersCache;
        }
        $rbac = $this->rbac();
        return $this->usersCache = $rbac === null ? [] : $rbac->section(RbacStorage::USERS);
    }

    private function rbac(): ?RbacStorage
    {
        if ($this->rbac !== null) {
            return $this->rbac;
        }
        try {
            return $this->rbac = new DbRbacStorage();
        } catch (\Throwable) {
            try {
                return $this->rbac = new RbacStorage();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /** 引擎关键动作落平台日志（失败不阻断业务）。 */
    private function journal(string $action, array $context): void
    {
        try {
            (new LoggingService())->info('[approval] ' . $action, $context);
        } catch (\Throwable) {
            // 日志写入失败不影响审批主流程
        }
    }
}
