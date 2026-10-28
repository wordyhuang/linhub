<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use think\facade\Db;

/**
 * 单据查询服务（manifest.services.provides: trade.doc_query，V_1.1）。
 *
 * 面向矩阵侧向消费（林账对账）提供只读单据查询：
 *  - 按业务类型在日期区间内分页列出已生效单据（草稿/作废不计入）；
 *  - 查看单张单据主表 + 明细。
 *
 * 支持类型：sale_order（销售）、purchase_order（采购）、
 * sale_return（销售退货）、purchase_return（采购退货）、reconciliation（对账单）。
 * 本项目只读，不落库、不产生副作用。
 */
class DocQueryService
{
    /** 业务类型 => [主表, 明细表, 主表单号列, 主表金额列, 主-明细外键列] */
    private const TYPES = [
        'sale_order'      => ['lg_lin-trade_sale_order',         'lg_lin-trade_sale_order_item',         'order_no',    'total_amount', 'order_id'],
        'purchase_order'  => ['lg_lin-trade_purchase_order',     'lg_lin-trade_purchase_order_item',     'order_no',    'total_amount', 'order_id'],
        'sale_return'     => ['lg_lin-trade_sale_return',        'lg_lin-trade_sale_return_item',        'return_no',   'total_amount', 'return_id'],
        'purchase_return' => ['lg_lin-trade_purchase_return',    'lg_lin-trade_purchase_return_item',    'return_no',   'total_amount', 'return_id'],
        'reconciliation'  => ['lg_lin-trade_reconciliation',     null,                                    'rc_no',       'balance_amount', null],
    ];

    /** 生效主状态（草稿/作废不计入对账口径） */
    private const ACTIVE = ['submitted', 'partial', 'done', 'closed', 'generated', 'confirmed', 'sent'];

    /**
     * 分页查询某类型单据（按创建时间倒序）。
     *
     * @param string $type  业务类型（sale_order/purchase_order/sale_return/purchase_return/reconciliation）
     * @param array  $cond  start/end 日期（Y-m-d）；limit/page
     * @return array{total:int,list:array}
     */
    public function query(string $type, array $cond = []): array
    {
        $cfg = self::TYPES[$type] ?? null;
        if (null === $cfg) {
            throw new \InvalidArgumentException('不支持的单据类型：' . $type);
        }
        [$table, , $noCol] = $cfg;

        $limit = (int) ($cond['limit'] ?? 20);
        $limit = max(1, min(500, $limit));
        $page  = max(1, (int) ($cond['page'] ?? 1));

        $q = Db::name($table)->where('status', 'in', self::ACTIVE);
        if (!empty($cond['start'])) {
            $q->where('created_at', '>=', $cond['start'] . ' 00:00:00');
        }
        if (!empty($cond['end'])) {
            $q->where('created_at', '<=', $cond['end'] . ' 23:59:59');
        }
        if (isset($cond['target_id'])
            && in_array($type, ['sale_order', 'purchase_order', 'sale_return', 'purchase_return'], true)) {
            $targetCol = ('sale_order' === $type || 'sale_return' === $type) ? 'customer_id' : 'supplier_id';
            $q->where($targetCol, (int) $cond['target_id']);
        }
        if (isset($cond['customer_id'])) {
            $q->where('customer_id', (int) $cond['customer_id']);
        }
        if (isset($cond['supplier_id'])) {
            $q->where('supplier_id', (int) $cond['supplier_id']);
        }

        $total = (int) (clone $q)->count();
        $list  = $q->field('id, ' . $noCol . ' as doc_no, status, total_amount, created_by, created_at, updated_at')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list];
    }

    /**
     * 查询单张单据详情（主表 + 明细）。
     *
     * @param string $type 业务类型
     * @param int    $id   主表 id
     * @return array{header:array,items:array}|null 不存在返回 null
     */
    public function detail(string $type, int $id): ?array
    {
        $cfg = self::TYPES[$type] ?? null;
        if (null === $cfg) {
            throw new \InvalidArgumentException('不支持的单据类型：' . $type);
        }
        [$table, $itemTable, $noCol] = $cfg;

        $header = Db::name($table)->where('id', $id)->find();
        if (!$header) {
            return null;
        }
        if (null === $itemTable) {
            return ['header' => (array) $header, 'items' => []];
        }
        [, , , , $fkCol] = $cfg;
        $items = Db::name($itemTable)
            ->where($fkCol, $id)
            ->field('product_id, quantity, price, amount')
            ->select()
            ->toArray();

        return ['header' => (array) $header, 'items' => $items];
    }

    /** 当前支持的单据类型列表 */
    public function types(): array
    {
        return array_keys(self::TYPES);
    }
}