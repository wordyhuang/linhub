<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\recyclebin\RecycleBinService;

/**
 * 回收站管理超管页（RB 管理 GUI）—— admin 应用，仅超管
 *
 *  - GET  /admin/recyclebin_admin/index         页面空壳
 *  - GET  /admin/recyclebin_admin/rows          分页检索 JSON（namespace/source_table/item_key/keyword/orphaned）+ 用量统计
 *  - GET  /admin/recyclebin_admin/preview       单条详情 JSON（payload 格式化展示，只读）
 *  - GET  /admin/recyclebin_admin/export        单条导出 JSON（下载头，浏览器落盘）
 *  - POST /admin/recyclebin_admin/extend        调整保留期（days=0 永久 / N 天后到期）
 *  - POST /admin/recyclebin_admin/purge         彻底删除单条
 *  - POST /admin/recyclebin_admin/purgeBatch    批量彻底删除
 *  - POST /admin/recyclebin_admin/cleanExpired  手动清理全部到期条目
 *
 *  - 不提供恢复动作（D1）：恢复需包代码参与写回，admin 仅查看/导出/保留期/清理；
 *  - 全部写操作走 asAdmin=true 跨命名空间，并 audit 留痕。
 */
class RecycleBinAdmin extends AdminController
{
    private function service(): RecycleBinService
    {
        return new RecycleBinService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('recyclebin_admin/index');
    }

    /** JSON：回收站分页检索（超管视角，跨命名空间）+ 用量统计。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['namespace', 'source_table', 'item_key', 'keyword'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $orphaned = trim((string) $req->get('orphaned', ''));
        if ($orphaned !== '') {
            $filter['orphaned'] = $orphaned;
        }
        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        try {
            $result = $this->service()->list(null, $filter, $page, $size, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('检索失败：' . $e->getMessage());
        }
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $this->service()->stats(),
        ]);
    }

    /** JSON：单条详情（payload 解码后展示，只读）。 */
    public function preview()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $token = trim((string) $this->request->get('token', ''));
        if ($token === '') {
            return $this->jsonFail('缺少 token');
        }
        try {
            $row = $this->service()->get(null, $token, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
        if ($row === null) {
            return $this->jsonFail('条目不存在', 404);
        }
        return $this->jsonOk($row);
    }

    /** 单条导出 JSON 下载。 */
    public function export()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $token = trim((string) $this->request->get('token', ''));
        if ($token === '') {
            return $this->jsonFail('缺少 token');
        }
        try {
            $row = $this->service()->get(null, $token, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('读取失败：' . $e->getMessage());
        }
        if ($row === null) {
            return $this->jsonFail('条目不存在', 404);
        }
        $this->audit($gate, 'recyclebin.export', (string) ($row['namespace'] ?? '') . '/' . $token);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return response($json === false ? '{}' : $json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="recyclebin-' . $token . '.json"',
        ]);
    }

    /** 调整保留期：days=0 永久 / >0 N 天后到期。 */
    public function extend()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $token = trim((string) $req->post('token', ''));
        $days = (int) $req->post('days', 0);
        if ($token === '') {
            return $this->jsonFail('缺少 token');
        }
        if ($days < 0) {
            return $this->jsonFail('保留天数不能为负数');
        }
        try {
            $hit = $this->service()->extendTtl($token, $days, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('调整失败：' . $e->getMessage());
        }
        if (!$hit) {
            return $this->jsonFail('条目不存在', 404);
        }
        $this->audit($gate, 'recyclebin.extend', $token . ' days=' . $days);
        return $this->jsonOk(null, $days === 0 ? '已调整为永久保留' : '已调整为 ' . $days . ' 天后到期');
    }

    /** 彻底删除单条。 */
    public function purge()
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
            $removed = $this->service()->purge(null, $token, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
        if (!$removed) {
            return $this->jsonFail('条目不存在', 404);
        }
        $this->audit($gate, 'recyclebin.purge', $token);
        return $this->jsonOk(null, '条目已彻底删除');
    }

    /** 批量彻底删除。 */
    public function purgeBatch()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $raw = (string) $this->request->post('tokens', '');
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
        if ($tokens === []) {
            return $this->jsonFail('缺少 tokens');
        }
        try {
            $count = $this->service()->purgeBatch(null, $tokens, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('删除失败：' . $e->getMessage());
        }
        $this->audit($gate, 'recyclebin.purgeBatch', implode(',', $tokens));
        return $this->jsonOk(['count' => $count], '已彻底删除 ' . $count . ' 条');
    }

    /** 手动清理全部命名空间的到期条目。 */
    public function cleanExpired()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        try {
            $count = $this->service()->purgeExpired(null, true);
        } catch (\Throwable $e) {
            return $this->jsonFail('清理失败：' . $e->getMessage());
        }
        $this->audit($gate, 'recyclebin.cleanExpired', 'count=' . $count);
        return $this->jsonOk(['count' => $count], '已清理 ' . $count . ' 条到期条目');
    }

    /** 超管操作留痕，失败不阻断。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
