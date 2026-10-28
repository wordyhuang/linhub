<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\facade\Db;
use think\Request;

/**
 * 系统设置与工具（FR-MSG-02 / FR-TOOL-01~03 / FR-AUT-04 / FR-CUS-02 规则页）
 */
class SettingController extends LinCrmBaseController
{
    // ── GUI 页面（菜单入口；数据经 POST JSON 接口拉取） ────
    public function pageConfig()   { $this->checkPerm('lin-crm:config:manage');    return $this->render('setting/config'); }
    public function pagePoolRule() { $this->checkPerm('lin-crm:config:manage');    return $this->render('setting/poolrule'); }
    public function pageDict()     { $this->checkPerm('lin-crm:config:manage');    return $this->render('setting/dict'); }
    public function pageRecycle()  { $this->checkPerm('lin-crm:customer:delete');  return $this->render('setting/recycle'); }
    public function pageAudit()    { $this->checkPerm('lin-crm:config:manage');    return $this->render('setting/audit'); }

    /** 配置读写（12 项租户级；FR-MSG-02 提醒规则 + 公海规则） */
    public function config(Request $req)
    {
        $this->checkPerm('lin-crm:config:manage');
        if ($req->isPost()) {
            $patch = (array) $req->param('patch', []);
            $allow = ['customer.protect_days', 'customer.private_limit', 'customer.release_run_at',
                      'lead.followup_overdue_days', 'opportunity.win_rates', 'opportunity.stagnant_days',
                      'contract.require_audit', 'receivable.remind_offsets', 'phone.mask_enabled',
                      'recycle.retention_days', 'import.batch_size', 'report.cache_ttl'];
            foreach ($patch as $k => $v) {
                if (!in_array($k, $allow, true)) { return $this->fail('定义缺失：未知配置键 ' . $k); } // 防幻觉
                $store = $k === 'opportunity.win_rates' ? implode(',', (array) $v) : $v;
                Db::name('lg_lin-crm_dict')->insertIgnore([
                    'dict_type' => 'config', 'dict_key' => $k, 'dict_value' => (string) $store,
                ]) ?: Db::name('lg_lin-crm_dict')->where('dict_type', 'config')->where('dict_key', $k)
                    ->update(['dict_value' => (string) $store, 'updated_at' => now_fmt()]);
            }
            return $this->ok(null, '配置已保存（即时生效）');
        }
        $rows = Db::name('lg_lin-crm_dict')->where('dict_type', 'config')->select()->toArray();
        return $this->ok(['defaults' => config('crm'), 'overrides' => array_column($rows, 'dict_value', 'dict_key')]);
    }

    /** 公海规则（FR-CUS-02：保护期/上限/释放时点；单行 id=1） */
    public function poolRule(Request $req)
    {
        $this->checkPerm('lin-crm:config:manage');
        if ($req->isPost()) {
            Db::name('lg_lin-crm_pool_rule')->where('id', 1)->update([
                'protect_days' => (int) $req->param('protect_days', 30),
                'private_limit' => (int) $req->param('private_limit', 50),
                'release_run_at' => (string) $req->param('release_run_at', '02:00'),
                'updated_by' => $this->currentUserId(), 'updated_at' => now_fmt(),
            ]);
            return $this->ok(null, '公海规则已保存');
        }
        return $this->ok(Db::name('lg_lin-crm_pool_rule')->where('id', 1)->find() ?: []);
    }

    /** 字典管理（FR-TOOL-03：来源/行业/等级/线索来源枚举租户级维护；op=list 查询、其余为写操作） */
    public function dict(Request $req)
    {
        $this->checkPerm('lin-crm:config:manage');
        if ($req->isPost() && (string) $req->param('op', 'save') !== 'list') {
            $op = (string) $req->param('op', 'save');
            $id = (int) $req->param('id', 0);
            $data = [
                'dict_type' => (string) $req->param('dict_type'),
                'dict_key' => (string) $req->param('dict_key'),
                'dict_value' => (string) $req->param('dict_value'),
                'sort' => (int) $req->param('sort', 0),
                'status' => (int) $req->param('status', 1),
            ];
            if (in_array($op, ['save', ''], true) && ($data['dict_type'] === '' || $data['dict_key'] === '')) {
                return $this->fail('类型与键为必填项');
            }
            if ($op === 'delete') { Db::name('lg_lin-crm_dict')->where('id', $id)->delete(); }
            elseif ($id) { Db::name('lg_lin-crm_dict')->where('id', $id)->update($data + ['updated_at' => now_fmt()]); }
            else { Db::name('lg_lin-crm_dict')->insert($data); }
            return $this->ok(null, '字典已更新');
        }
        $type = (string) $req->param('dict_type', '');
        $q = Db::name('lg_lin-crm_dict')->where('dict_type', '<>', 'config');
        $type && $q->where('dict_type', $type);
        return $this->ok($q->order('dict_type')->order('sort')->select()->toArray());
    }

    /**
     * 回收站（FR-TOOL-02：列表/恢复/彻底删除；仅管理员；恢复查重 BND-07）。
     * 存储托管平台回收站服务（recyclebin，namespace=lin-crm），本包不自建表。
     */
    public function recycle(Request $req)
    {
        $this->checkPerm('lin-crm:customer:delete');
        $op  = (string) $req->param('op', 'index');
        $bin = \Lg\Pack\LinCrm\Service\RecycleBinGateway::svc();
        if ($op === 'index') {
            $page  = max(1, (int) $req->param('page', 1));
            $size  = max(1, (int) ($req->param('page_size', 20)));
            $res   = $bin->list(null, ['keyword' => trim((string) $req->param('keyword', ''))], $page, $size);
            return $this->ok([
                'list' => $res['items'], 'total' => $res['total'],
                'pageSize' => $res['size'], 'currentPage' => $res['page'],
            ]);
        }
        $token = trim((string) $req->param('token'));
        if ($token === '') { return $this->fail('token 必填'); }

        if ($op === 'restore') {
            // restore 返回数据副本（条目保留），写回原表由本包完成
            $item = $bin->restore(null, $token);
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $srcTable = (string) ($item['source_table'] ?? '');
            $srcId = (int) ($item['source_pk'] ?? 0);
            if ($srcTable === 'lg_lin-crm_customer') {
                // 恢复查重（BND-07：主体名称已与新客户撞单 → 拦截并提示走合并）
                if (Db::name('lg_lin-crm_customer')->whereNull('deleted_at')->where('name', $payload['name'] ?? '')->find()) {
                    return $this->fail('恢复撞单：同名客户已存在，请走客户合并（FR-CUS-03）');
                }
            }
            Db::name($srcTable)->where('id', $srcId)->update(['deleted_at' => null, 'updated_at' => now_fmt()]);
            $bin->purge(null, $token); // 恢复完成后清除托管条目
            return $this->ok(null, '已恢复');
        }
        if ($op === 'purge') { // 彻底删除（不可逆）：源行物理删除 + 清托管条目
            $item = $bin->get(null, $token);
            if (!$item) { return $this->fail('回收站记录不存在'); }
            Db::name((string) $item['source_table'])->where('id', (int) $item['source_pk'])->delete();
            $bin->purge(null, $token);
            $this->audit('delete', (string) $item['source_table'], (int) $item['source_pk'], 'purge from recycle bin');
            return $this->ok(null, '已彻底删除');
        }
        return $this->fail('定义缺失：未知操作 ' . $op);
    }

    /** 审计日志查询（FR-AUT-04：五类行为全量留痕，仅管理员）。注：方法名 auditLogs，避免与基类 audit() 撞名；URL 仍为 crm/setting/audit */
    public function auditLogs(Request $req)
    {
        $this->checkPerm('lin-crm:config:manage');
        $q = Db::name('lg_lin-crm_audit_log');
        if ($v = $req->param('action')) { $q->where('action', $v); }
        if ($v = $req->param('actor_id')) { $q->where('actor_id', (int) $v); }
        return $this->ok($this->pageShape($q->order('created_at', 'desc')->paginate((int) ($req->param('page_size', 20)))));
    }

    /** 批量导入（FR-TOOL-01/FR-CUS-07：逐行校验、撞单跳过、错误行回执；批次 crm.import.batch_size） */
    public function import(Request $req)
    {
        $this->checkPerm('lin-crm:customer:create');
        $rows = (array) $req->param('rows', []); // [{name,phone,industry,source,level,owner_id?}]
        $batchSize = (int) (config('crm.import.batch_size') ?? 1000);
        if (count($rows) > $batchSize) { return $this->fail('单批不超过 ' . $batchSize . ' 行'); }
        $okRows = []; $errors = [];
        foreach ($rows as $i => $r) {
            $name = preg_replace('/\s+/u', '', trim((string) ($r['name'] ?? '')));
            $phone = trim((string) ($r['phone'] ?? ''));
            if (!$name || !$phone) { $errors[] = ['line' => $i + 1, 'reason' => '名称/手机号缺失']; continue; }
            if (Db::name('lg_lin-crm_customer')->whereNull('deleted_at')
                ->where(function ($w) use ($name, $phone) { $w->where('name', $name)->whereOr('phone', $phone); })->find()) {
                $errors[] = ['line' => $i + 1, 'reason' => '撞单跳过（GR-4）']; continue; // BND-04
            }
            $okRows[] = $r;
        }
        foreach ($okRows as &$r) {
            (new \Lg\Pack\LinCrm\Model\Customer())->q()->insert([
                'code' => \Lg\Pack\LinCrm\Service\CodeService::next('customer'),
                'name' => preg_replace('/\s+/u', '', trim($r['name'])), 'phone' => trim($r['phone']),
                'industry' => (string) ($r['industry'] ?? ''), 'source' => (string) ($r['source'] ?? ''),
                'level' => (string) ($r['level'] ?? 'normal'),
                'owner_id' => (int) ($r['owner_id'] ?? 0), // 默认进公海，可选直接分配
                'pool_status' => empty($r['owner_id']) ? 'public_pool' : 'private_pool',
                'protect_until' => empty($r['owner_id']) ? null : date('Y-m-d H:i:s', time() + 30 * 86400),
                'updated_at' => now_fmt(),
            ]);
        }
        $this->audit('import', 'customer', 0, 'total:' . count($rows) . ' ok:' . count($okRows) . ' err:' . count($errors));
        return $this->ok(['imported' => count($okRows), 'errors' => $errors], '导入完成（错误行见回执）');
    }

    /** 定时任务：回收站到期清理（幂等可重跑；转发平台服务，清理本包命名空间到期条目） */
    public static function cronRecyclePurge(): array
    {
        $purged = \Lg\Pack\LinCrm\Service\RecycleBinGateway::svc()->purgeExpired(null);
        return ['purged' => $purged];
    }
}
