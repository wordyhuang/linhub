<?php

declare(strict_types=1);

namespace Lg\Pack\LinCrm\Controller;

use think\facade\Db;
use think\Request;

/**
 * 跟单看板（A+B 方案）：
 *  - A：以客户视角聚合林销（lin-trade）销售订单履约进度——只读，数据主权在林销，
 *       经 lg_lin-trade_customer.name ↔ lg_lin-crm_customer.name 弱关联（两包 id 空间不同，
 *       trade 侧无来源映射字段，名称是唯一稳定键；名称撞单时显示「未匹配」不影响订单展示）。
 *  - B：跟单记录复用 lg_lin-crm_follow_up（biz_type='order'，迁移 008 扩枚举）+ 停滞预警 cron。
 *  - RL-5 弱依赖降级：林销未安装/表不存在时 available=false 空态返回，绝不抛错。
 */
final class OrderController extends LinCrmBaseController
{
    /** 林销销售订单状态 → 中文标签（与 lin-trade StatusLabels 口径一致） */
    public const STATUS_LABELS = [
        'draft'     => '草稿',
        'submitted' => '已提交',
        'partial'   => '部分出库',
        'done'      => '已完成',
        'closed'    => '已关闭',
        'voided'    => '已作废',
    ];

    /** 跟单看板页 */
    public function pageIndex()
    {
        $this->checkPerm('lin-crm:order:view');
        return $this->render('order/index');
    }

    /** 订单列表：分页 + 出库进度聚合 + CRM 客户名称弱关联 */
    public function rows(Request $req)
    {
        $this->checkPerm('lin-crm:order:view');
        if (!$this->tradeAvailable()) {
            return $this->ok(['available' => false, 'list' => [], 'total' => 0, 'pageSize' => 20, 'currentPage' => 1],
                '林销未安装，跟单看板暂无数据');
        }

        $page  = max(1, (int) $req->param('page', 1));
        $size  = min(100, max(1, (int) $req->param('page_size', 20)));
        $kw    = trim((string) $req->param('keyword', ''));
        $st    = (string) $req->param('status', '');
        $mine  = (int) $req->param('only_mine', 0);

        $where  = ' WHERE 1=1';
        $bind   = [];
        if ($kw !== '') {
            $where .= ' AND (so.order_no LIKE ? OR tc.name LIKE ? OR c.name LIKE ?)';
            $bind[] = '%' . $kw . '%'; $bind[] = '%' . $kw . '%'; $bind[] = '%' . $kw . '%';
        }
        if ($st !== '') {
            $where .= ' AND so.status = ?';
            $bind[] = $st;
        }
        $uid = $this->currentUserId();
        if ($mine === 1 && $uid > 0) {
            $where .= ' AND c.owner_id = ?';
            $bind[] = $uid;
        }

        $base = ' FROM `lg_lin-trade_sale_order` so'
            . ' JOIN `lg_lin-trade_customer` tc ON tc.id = so.customer_id'
            . ' LEFT JOIN `lg_lin-crm_customer` c ON c.name = tc.name AND c.deleted_at IS NULL'
            . ' LEFT JOIN (SELECT order_id, SUM(quantity) AS planned, SUM(outbound_qty) AS outbound,'
            . '   SUM(returned_qty) AS returned FROM `lg_lin-trade_sale_order_item` GROUP BY order_id) it ON it.order_id = so.id';
        $total = (int) (Db::query('SELECT COUNT(*) AS c' . $base . $where, $bind)[0]['c'] ?? 0);
        $list  = Db::query(
            'SELECT so.id, so.order_no, so.status, so.total_amount, so.created_at, so.updated_at,'
            . ' tc.name AS trade_customer_name, c.id AS crm_customer_id, c.name AS crm_customer_name, c.owner_id,'
            . ' IFNULL(it.planned, 0) AS planned_qty, IFNULL(it.outbound, 0) AS outbound_qty, IFNULL(it.returned, 0) AS returned_qty'
            . $base . $where . ' ORDER BY so.updated_at DESC, so.id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
            $bind
        );
        foreach ($list as &$r) {
            $r['status_label']  = self::STATUS_LABELS[$r['status']] ?? (string) $r['status'];
            $r['progress_pct']  = (float) $r['planned_qty'] > 0
                ? round(min(100.0, (float) $r['outbound_qty'] / (float) $r['planned_qty'] * 100), 1) : 0.0;
            $r['linked']        = (int) ($r['crm_customer_id'] ?? 0) > 0;
        }
        unset($r);

        return $this->ok(['available' => true, 'list' => $list, 'total' => $total, 'pageSize' => $size, 'currentPage' => $page]);
    }

    /** 订单明细（行项目出库进度） */
    public function items(Request $req)
    {
        $this->checkPerm('lin-crm:order:view');
        $orderId = (int) $req->param('order_id');
        if (!$this->tradeAvailable()) {
            return $this->ok([]);
        }
        $rows = Db::query(
            'SELECT i.id, i.quantity, i.outbound_qty, i.returned_qty, i.price, i.amount, IFNULL(p.name, ?) AS product_name'
            . ' FROM `lg_lin-trade_sale_order_item` i LEFT JOIN `lg_lin-trade_product` p ON p.id = i.product_id'
            . ' WHERE i.order_id = ? ORDER BY i.id',
            ['未知商品', $orderId]
        );
        foreach ($rows as &$r) {
            $r['pct'] = (float) $r['quantity'] > 0
                ? round(min(100.0, (float) $r['outbound_qty'] / (float) $r['quantity'] * 100), 1) : 0.0;
        }
        unset($r);
        return $this->ok($rows);
    }

    /** 跟单记录时间线（B：复用 follow_up，biz_type='order'） */
    public function timeline(Request $req)
    {
        $this->checkPerm('lin-crm:order:view');
        $orderId = (int) $req->param('order_id');
        return $this->ok(Db::name('lg_lin-crm_follow_up')->where('biz_type', 'order')->where('biz_id', $orderId)
            ->order('created_at', 'desc')->limit(60)->select()->toArray());
    }

    /** 新增跟单记录（B：内容必填；方式限定 follow_up 枚举） */
    public function follow(Request $req)
    {
        $this->checkPerm('lin-crm:order:follow');
        if (!$this->tradeAvailable()) {
            return $this->fail('林销未安装，无法跟单');
        }
        $orderId = (int) $req->param('order_id');
        $order = Db::query('SELECT id, order_no FROM `lg_lin-trade_sale_order` WHERE id = ?', [$orderId]);
        if (!$order) {
            return $this->fail('订单不存在');
        }
        $method = (string) $req->param('method', 'phone');
        if (!in_array($method, ['phone', 'visit', 'wechat', 'other'], true)) {
            return $this->fail('定义缺失：跟进方式不合法');
        }
        $content = trim((string) $req->param('content', ''));
        if ($content === '') {
            return $this->fail('跟单内容必填');
        }
        Db::name('lg_lin-crm_follow_up')->insert([
            'biz_type' => 'order', 'biz_id' => $orderId,
            'method'   => $method, 'content' => $content,
            'next_at'  => $req->param('next_at') ?: null,
            'user_id'  => $this->currentUserId(),
        ]);
        return $this->ok(null, '跟单记录已保存');
    }

    /** 定时任务：跟单停滞预警（B）——在途订单超 N 天未更新，通知匹配到的 CRM 客户负责人（当日幂等） */
    public static function cronOrderStagnantAlert(): array
    {
        $days = (int) (config('crm.order.stagnant_days') ?? 7);
        try {
            $rows = Db::query(
                "SELECT so.id, so.order_no, so.status, c.owner_id, c.name AS crm_name"
                . " FROM `lg_lin-trade_sale_order` so"
                . ' JOIN `lg_lin-trade_customer` tc ON tc.id = so.customer_id'
                . ' LEFT JOIN `lg_lin-crm_customer` c ON c.name = tc.name AND c.deleted_at IS NULL'
                . " WHERE so.status IN ('draft','submitted','partial')"
                . ' AND so.updated_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            );
        } catch (\Throwable $e) {
            return ['alerted' => 0, 'skipped' => 'trade not installed']; // RL-5 弱依赖降级
        }
        $n = 0;
        foreach ($rows as $r) {
            if ((int) ($r['owner_id'] ?? 0) <= 0) {
                continue; // 未匹配到 CRM 客户/负责人：无处投递，跳过
            }
            $dup = Db::name('lg_lin-crm_notify_log')->where('biz_type', 'trade_order')->where('biz_id', $r['id'])
                ->whereLike('title', '订单停滞%')->whereTime('created_at', 'today')->count();
            if ($dup) {
                continue; // 当日幂等（仿商机停滞预警）
            }
            Db::name('lg_lin-crm_notify_log')->insert([
                'user_id'  => (int) $r['owner_id'],
                'title'    => '订单停滞预警',
                'content'  => '销售订单「' . $r['order_no'] . '」（客户 ' . $r['crm_name'] . '）已 ' . $days . ' 天未更新，状态 ' . (self::STATUS_LABELS[$r['status']] ?? $r['status']),
                'biz_type' => 'trade_order', 'biz_id' => (int) $r['id'],
            ]);
            $n++;
        }
        return ['alerted' => $n];
    }

    /** 林销可用性探测（RL-5：表不存在即视为未安装） */
    private function tradeAvailable(): bool
    {
        try {
            Db::query('SELECT 1 FROM `lg_lin-trade_sale_order` LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
