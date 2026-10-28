<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use think\facade\App;
use think\facade\Db;

/**
 * 报表统计服务。
 *
 * 提供老板视角的核心指标：库存余额、采购/销售汇总、流水趋势、呆滞/预警。
 */
class ReportService
{
    /**
     * 库存总览：商品总数、在库商品数、总库存金额近似值、预警商品数。
     */
    public function stockOverview(): array
    {
        $avgPrice = Db::name('lg_lin-trade_product')
            ->alias('p')
            ->join('lg_lin-trade_purchase_order_item pi', 'pi.product_id = p.id')
            ->field('p.id, AVG(pi.price) as avg_price')
            ->group('p.id')
            ->buildSql();

        $rows = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->leftJoin("({$avgPrice}) avgp", 'avgp.id = i.product_id')
            ->field('SUM(i.quantity) as qty, SUM(i.quantity * COALESCE(avgp.avg_price,0)) as amount, COUNT(DISTINCT i.product_id) as prod_count')
            ->select()
            ->toArray();

        $row  = $rows[0] ?? [];
        $low  = count($this->lowStockPreview());

        $productCount = Db::name('lg_lin-trade_product')->where('status', 1)->count();

        return [
            'product_count'  => (int) $productCount,
            'stock_products' => (int) ($row['prod_count'] ?? 0),
            'total_qty'      => (float) ($row['qty'] ?? 0),
            'stock_amount'   => round((float) ($row['amount'] ?? 0), 2),
            'low_stock_count'=> $low,
        ];
    }

    /**
     * 采购/销售汇总（按天）。
     *
     * @param array $cond start/end 日期，type: purchase|sale
     */
    public function orderSummary(array $cond): array
    {
        $type      = $cond['type'] ?? 'purchase';
        $table     = 'purchase' === $type ? 'purchase_order' : 'sale_order';
        $statusCond = $this->orderStatusCond();

        $query = Db::name('lg_lin-trade_' . $table)
            ->field("DATE_FORMAT(created_at,'%Y-%m-%d') as day, COUNT(*) as order_cnt, SUM(total_amount) as amount")
            ->where('status', 'not in', $statusCond['exclude']);
        if (!empty($cond['start'])) {
            $query->where('created_at', '>=', $cond['start'] . ' 00:00:00');
        }
        if (!empty($cond['end'])) {
            $query->where('created_at', '<=', $cond['end'] . ' 23:59:59');
        }
        $query->group('day')->order('day', 'asc');

        return $query->select()->toArray();
    }

    /**
     * 库存流水趋势（出入库总量按天，用于图表）。
     */
    public function inventoryTrend(array $cond): array
    {
        $query = Db::name('lg_lin-trade_inventory_log')
            ->field("DATE_FORMAT(created_at,'%Y-%m-%d') as day,
                     SUM(CASE WHEN change_qty > 0 THEN change_qty ELSE 0 END) as in_qty,
                     SUM(CASE WHEN change_qty < 0 THEN -change_qty ELSE 0 END) as out_qty")
            ->where('change_qty', '<>', 0);
        if (!empty($cond['start'])) {
            $query->where('created_at', '>=', $cond['start'] . ' 00:00:00');
        }
        if (!empty($cond['end'])) {
            $query->where('created_at', '<=', $cond['end'] . ' 23:59:59');
        }
        $query->group('day')->order('day', 'asc');

        return $query->select()->toArray();
    }

    /**
     * 呆滞清单（PRD 3.4.6，trade.stock.dull_days 默认 90）。
     * 现货 > 0 且连续 N 天无任何出库反馈的商品；含占用金额（参考进价口径）与促销建议价。
     */
    public function dullStock(int $days = 90, int $limit = 100): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $active = Db::name('lg_lin-trade_inventory_log')
            ->where('created_at', '>', $cutoff)
            ->field('product_id');
        $activeSql = $active->buildSql();

        $rows = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereExists($activeSql ? "SELECT 1 FROM {$activeSql} a WHERE a.product_id != i.product_id" : '')
            ->field('i.product_id, p.name, p.product_no, p.reference_cost_price, p.abc_class, SUM(i.quantity) as quantity, MAX(i.updated_at) as last_at')
            ->group('i.product_id, p.name, p.product_no, p.reference_cost_price, p.abc_class')
            ->order('quantity', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $margin = (float) $this->config('stock.dull_promo_margin', 10);
        foreach ($rows as &$r) {
            $r['quantity']       = (float) $r['quantity'];
            $cost                = (float) $r['reference_cost_price'];
            $r['occupancy_amount'] = round($cost * $r['quantity'], 2);
            $r['dull_days']        = (int) $days;
            $r['promo_suggestion'] = round($cost * (1 + $margin / 100), 2);
        }
        unset($r);

        return $rows;
    }

    /**
     * 滞销商品（PRD 3.4.6 P1，trade.stock.turnover_days 默认 60）。
     * 周转天数 = 近 90 天平均库存量 ÷ 近 90 天日均出库量；超过阈值标滞销。
     */
    public function turnoverProducts(int $days = 90, int $limit = 100): array
    {
        $threshold = (int) $this->config('stock.turnover_days', 60);
        $since     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // 近 N 天各商品出库总量（按销售出库 / 采购退货等扣减流水）
        $out = Db::name('lg_lin-trade_inventory_log')
            ->where('created_at', '>', $since)
            ->where('change_qty', '<', 0)
            ->field('product_id, SUM(-change_qty) as out_qty')
            ->group('product_id')
            ->buildSql();

        $rows = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->leftJoin("({$out}) o", 'o.product_id = i.product_id')
            ->field('i.product_id, p.name, p.product_no, AVG(i.quantity) as avg_qty, COALESCE(o.out_qty,0) as out_qty, p.abc_class')
            ->group('i.product_id, p.name, p.product_no, o.out_qty, p.abc_class')
            ->having('COALESCE(o.out_qty,0) > 0')
            ->select()
            ->toArray();

        $list  = [];
        $daily = $days > 0 ? $days : 1;
        foreach ($rows as $r) {
            $avgQty   = (float) $r['avg_qty'];
            $outQty   = (float) $r['out_qty'];
            $turnover = ($outQty / $daily) > 0 ? $avgQty / ($outQty / $daily) : 0.0;
            $r['turnover_days'] = round($turnover, 1);
            if ($turnover > $threshold) {
                $list[] = $r;
            }
        }
        return $list;
    }

    /**
     * ABC 分类重算（PRD 3.4.7，trade.stock.abc_rule 默认 80/15/5，每月 1 日定时/手动）。
     * 按近 90 天出库金额降序累计；A 类累计 ≤ A 占比、B 类 ≤ A+B，其余 C；标签写回商品档案。
     *
     * @return array{total:int,a:int,b:int,c:int,updated:int}
     */
    public function abcClassify(?int $operator = null, int $days = 90): array
    {
        $rule   = explode('/', (string) $this->config('stock.abc_rule', '80/15/5'));
        $aRatio = (float) ($rule[0] ?? 80);
        $bRatio = $aRatio + (float) ($rule[1] ?? 15);
        $since  = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // 近 90 天销售出库金额（按已出库行）：outbound_qty × price
        $rows = Db::name('lg_lin-trade_sale_order_item')
            ->alias('si')
            ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
            ->where('so.created_at', '>', $since)
            ->where('so.status', 'in', ['submitted', 'partial', 'done', 'closed'])
            ->where('si.outbound_qty', '>', 0)
            ->field('si.product_id, SUM(si.outbound_qty * si.price) as amount')
            ->group('si.product_id')
            ->order('amount', 'desc')
            ->select()
            ->toArray();

        $total = (float) array_sum(array_column($rows, 'amount'));
        if ($total <= 0) {
            return ['total' => 0, 'a' => 0, 'b' => 0, 'c' => 0, 'updated' => 0];
        }

        $cum    = 0.0;
        $assign = [];
        foreach ($rows as $r) {
            $cum += (float) $r['amount'];
            $pct  = $cum / $total * 100;
            $class = 'C';
            if ($pct <= $aRatio) {
                $class = 'A';
            } elseif ($pct <= $bRatio) {
                $class = 'B';
            }
            $assign[(int) $r['product_id']] = $class;
        }

        $updated = 0;
        foreach ($assign as $pid => $class) {
            $upd = Db::name('lg_lin-trade_product')->where('id', $pid)->update(['abc_class' => $class]);
            $updated += $upd;
        }

        $counts = ['a' => 0, 'b' => 0, 'c' => 0];
        foreach ($assign as $class) {
            $counts[strtolower($class)]++;
        }

        return [
            'total'   => count($assign),
            'a'       => $counts['a'],
            'b'       => $counts['b'],
            'c'       => $counts['c'],
            'updated' => $updated,
        ];
    }

    /**
     * 按 ABC 分类过滤的商品（V_1.1，库存报表/呆滞清单/盘点可筛选）。
     */
    public function abcProducts(string $class = ''): array
    {
        $q = Db::name('lg_lin-trade_product')->field('id, name, product_no, abc_class, reference_cost_price, status');
        if ('' !== $class && in_array($class, ['A', 'B', 'C'], true)) {
            $q->where('abc_class', $class);
        }
        return $q->select()->toArray();
    }

    /**
     * 销售排行（PRD 3.5），按商品/客户分组，含数量、金额与毛利。
     * 毛利口径取 trade.report.profit_method（estimate 参考进价估算 / weighted 移动加权实际成本）。
     */
    public function saleRanking(array $cond = [], int $limit = 50): array
    {
        $method = (string) $this->config('report.profit_method', 'estimate');
        $start  = $cond['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $end    = $cond['end'] ?? date('Y-m-d');

        // 出库行 × 商品参考进价 / 客户
        $rows = Db::name('lg_lin-trade_sale_order_item')
            ->alias('si')
            ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
            ->join('lg_lin-trade_product p', 'p.id = si.product_id')
            ->leftJoin('lg_lin-trade_inventory inv', 'inv.product_id = p.id')
            ->where('so.status', 'in', ['submitted', 'partial', 'done', 'closed'])
            ->whereBetween('so.created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->where('si.outbound_qty', '>', 0)
            ->field('si.product_id, p.name as product_name, p.product_no, p.spec,
                     p.reference_cost_price,
                     so.customer_id,
                     SUM(si.outbound_qty) as qty, SUM(si.outbound_qty * si.price) as amount,
                     AVG(inv.weighted_cost) as weighted_cost')
            ->group('si.product_id, p.name, p.product_no, p.spec, p.reference_cost_price, so.customer_id')
            ->order('amount', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $qty    = (float) $r['qty'];
            $amount = (float) $r['amount'];
            $cost   = ('weighted' === $method) ? (float) ($r['weighted_cost'] ?? 0) : (float) $r['reference_cost_price'];
            $profit = round($amount - $cost * $qty, 2);
            $r['profit_method'] = $method;
            $r['cost_price']    = round($cost, 4);
            $r['profit']        = $profit;
            // weighted 口径下无成本（均价 0）单列"未核算"
            $r['unaccounted'] = ('weighted' === $method && $cost <= 0) ? 1 : 0;
            $r['amount']       = round($amount, 2);
        }
        unset($r);

        return $rows;
    }

    /**
     * 进销存汇总（PRD 3.5 报表域）。
     *
     * 按商品聚合：
     *  - 期初库存 open_qty：统计起始时间之前累计净流入（created_at < start）；
     *  - 本期采购 buy_qty：期间内 purchase_in 入库量；
     *  - 本期销售 sell_qty / 金额 sell_amount：期间内销售出库行（outbound_qty>0）数量与金额；
     *  - 期末库存 end_qty：实时库存跨仓库汇总；
     *  - 成本 cost：销售折量 × 单位成本（estimate=参考进价 / weighted=移动加权）；
     *  - 毛利 profit = 销售金额 − 成本。
     *
     * @param array $cond     start/end 日期（Y-m-d，可空）
     * @param int   $page     页码
     * @param int   $pageSize 每页条数
     * @return array{total:int, list:array}
     */
    public function stockSummary(array $cond = [], int $page = 1, int $pageSize = 20): array
    {
        $start  = (string) ($cond['start'] ?? '');
        $end    = (string) ($cond['end'] ?? '');
        $method = (string) $this->config('report.profit_method', 'estimate');

        // 期初：start 之前累计净流入（无起始时间则期初为 0）
        $openQ = Db::name('lg_lin-trade_inventory_log')->field('product_id, SUM(change_qty) as open_qty');
        if ('' !== $start) {
            $openQ->where('created_at', '<', $start . ' 00:00:00');
        } else {
            $openQ->where('id', '<', 0);
        }
        $openSql = $openQ->group('product_id')->buildSql();

        // 本期采购入库量
        $buyQ = Db::name('lg_lin-trade_inventory_log')
            ->field('product_id, SUM(change_qty) as buy_qty')
            ->where('change_type', InventoryService::TYPE_PURCHASE_IN);
        if ('' !== $start) {
            $buyQ->where('created_at', '>=', $start . ' 00:00:00');
        }
        if ('' !== $end) {
            $buyQ->where('created_at', '<=', $end . ' 23:59:59');
        }
        $buySql = $buyQ->group('product_id')->buildSql();

        // 本期销售出库量与金额（按已出库行，取单据生效态）
        $saleQ = Db::name('lg_lin-trade_sale_order_item')
            ->alias('si')
            ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
            ->where('so.status', 'in', ['submitted', 'partial', 'done', 'closed'])
            ->where('si.outbound_qty', '>', 0)
            ->field('si.product_id, SUM(si.outbound_qty) as sell_qty, SUM(si.outbound_qty * si.price) as sell_amount');
        if ('' !== $start) {
            $saleQ->where('so.created_at', '>=', $start . ' 00:00:00');
        }
        if ('' !== $end) {
            $saleQ->where('so.created_at', '<=', $end . ' 23:59:59');
        }
        $saleSql = $saleQ->group('si.product_id')->buildSql();

        // 实时库存跨仓库聚合
        $stockQ = Db::name('lg_lin-trade_inventory')
            ->field('product_id, SUM(quantity) as end_qty, AVG(weighted_cost) as weighted_cost')
            ->group('product_id')
            ->buildSql();

        $rows = Db::name('lg_lin-trade_product')
            ->alias('p')
            ->leftJoin("({$stockQ}) st", 'st.product_id = p.id')
            ->leftJoin("({$openSql}) op", 'op.product_id = p.id')
            ->leftJoin("({$buySql}) bq", 'bq.product_id = p.id')
            ->leftJoin("({$saleSql}) sq", 'sq.product_id = p.id')
            ->where('p.status', 1)
            ->field('p.id as product_id, p.name, p.product_no, p.spec, p.reference_cost_price,
                     COALESCE(st.end_qty,0) as end_qty, COALESCE(st.weighted_cost,0) as weighted_cost,
                     COALESCE(op.open_qty,0) as open_qty, COALESCE(bq.buy_qty,0) as buy_qty,
                     COALESCE(sq.sell_qty,0) as sell_qty, COALESCE(sq.sell_amount,0) as sell_amount')
            // HAVING 只能引用 SELECT 结果的别名，不能带表别名前缀
            ->having('COALESCE(end_qty,0) <> 0 OR COALESCE(open_qty,0) <> 0 '
                . 'OR COALESCE(buy_qty,0) <> 0 OR COALESCE(sell_qty,0) <> 0 '
                . 'OR COALESCE(sell_amount,0) <> 0')
            ->order('sell_amount', 'desc')
            ->select()
            ->toArray();

        $total = count($rows);
        $page   = max(1, $page);
        $pageSize = max(1, $pageSize);

        $list = [];
        foreach (array_slice($rows, ($page - 1) * $pageSize, $pageSize) as $r) {
            $sellQty = (float) $r['sell_qty'];
            $cost    = ('weighted' === $method)
                ? (float) $r['weighted_cost']
                : (float) $r['reference_cost_price'];
            $r['amount'] = round((float) $r['sell_amount'], 2);
            $r['cost']   = round($cost * $sellQty, 2);
            $r['profit'] = round($r['amount'] - $r['cost'], 2);
            $r['profit_method'] = $method;
            $list[] = $r;
        }

        return ['total' => $total, 'list' => $list];
    }

    /**
     * 呆滞库存：N 天内无出入库流水的在库商品。
     */
    public function stagnantStock(int $days = 90, int $limit = 100): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $active = Db::name('lg_lin-trade_inventory_log')
            ->where('created_at', '>', $cutoff)
            ->field('product_id, warehouse_id');
        $activeSql = $active->buildSql();

        // 在库商品中，排除 cutoff 之后仍有出入库流水的商品（按商品粒度统计呆滞）
        return Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereNotExists("SELECT 1 FROM {$activeSql} l WHERE l.product_id = i.product_id")
            ->field('i.product_id, p.name, p.product_no, SUM(i.quantity) as quantity')
            ->group('i.product_id, p.name, p.product_no')
            ->order('quantity', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /** 预警商品预览（复用库存预警逻辑，限量 20） */
    private function lowStockPreview(): array
    {
        return Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->whereColumn('i.quantity', '<', 'p.safety_stock')
            ->where('p.status', 1)
            ->limit(20)
            ->select()
            ->toArray();
    }

    /** 统计时排除的状态（草稿/作废不计入已生效单据） */
    private function orderStatusCond(): array
    {
        return ['exclude' => ['draft', 'voided']];
    }

    /** 读取租户配置（trade.* 下挂 lin-trade 包命名空间），缺失回退默认值 */
    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}