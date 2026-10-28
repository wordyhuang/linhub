<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 定时任务服务（PRD 3.x 支撑层）。
 *
 * 宿主任务调度器（TaskScheduler 契约，M8）只暴露接口、正文由宿主/外部 cron 承担；
 * 本服务承载两类周期业务的执行体，供调度器或外部 cron 触发：
 *  - runDaily()   每日 08:00：采购到货提醒 / 采购超期提醒 / 库存预警 / 呆滞扫描 / 草稿到期前提醒；
 *  - runMonthlyAbc() 每月 1 日：ABC 分类重算（trade.stock.abc_rule）。
 *
 * 结果一律经事件总线（messaging）广播，供站内消息/通知订阅消费；失败仅记录不中断。
 */
class ScheduledTaskService
{
    /**
     * 每日任务（建议每日 08:00 触发），返回各项统计。
     */
    public function runDaily(): array
    {
        $summary = [
            'arrival'     => $this->arrivalReminders(),
            'overdue'     => $this->overdueAlerts(),
            'low_stock'   => $this->lowStockAlerts(),
            'dull_stock'  => $this->dullStockScan(),
            'draft'       => $this->draftReminders(),
        ];
        return $summary;
    }

    /**
     * 每月 ABC 分类重算（建议每月 1 日触发）。
     */
    public function runMonthlyAbc(?int $operator = null): array
    {
        $report = App::make(ReportService::class);
        $result = $report->abcClassify($operator);
        TradeEvents::publish(TradeEvents::ABC_CALCULATED, $result);
        return $result;
    }

    // ---------------- 每日子任务 ----------------

    /** 采购到货提醒：预计到货日在未来 N 天以内、业务仍进行中（submitted/partial）的采购单。 */
    private function arrivalReminders(): array
    {
        $days = max(0, (int) $this->config('purchase.arrival_alert_days', 3));
        $until = date('Y-m-d', strtotime('+' . $days . ' days'));
        $rows = Db::name('lg_lin-trade_purchase_order')
            ->where('status', 'in', ['submitted', 'partial'])
            ->whereNotNull('expected_arrival_at')
            ->where('expected_arrival_at', '<=', $until)
            ->field('id, order_no, supplier_id, expected_arrival_at')
            ->order('expected_arrival_at', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as &$r) {
            $r['expected_arrival_at'] = (string) ($r['expected_arrival_at'] ?? '');
        }
        unset($r);
        if ($rows) {
            TradeEvents::publish(TradeEvents::ARRIVAL_REMIND, ['count' => count($rows), 'orders' => $rows]);
        }
        return $rows;
    }

    /** 采购超期提醒：预计到货日已过、仍未完成入库的采购单（trade.purchase.overdue_alert=on）。 */
    private function overdueAlerts(): array
    {
        if ('off' === (string) $this->config('purchase.overdue_alert', 'on')) {
            return [];
        }
        $today = date('Y-m-d');
        $rows = Db::name('lg_lin-trade_purchase_order')
            ->where('status', 'in', ['submitted', 'partial'])
            ->whereNotNull('expected_arrival_at')
            ->where('expected_arrival_at', '<', $today)
            ->field('id, order_no, supplier_id, expected_arrival_at')
            ->order('expected_arrival_at', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as &$r) {
            $r['expected_arrival_at'] = (string) ($r['expected_arrival_at'] ?? '');
            $r['overdue']             = true;
        }
        unset($r);
        if ($rows) {
            TradeEvents::publish(TradeEvents::OVERDUE_REMIND, ['count' => count($rows), 'orders' => $rows]);
        }
        return $rows;
    }

    /** 库存预警：在库数量低于安全库存的启用商品（现货>0）。 */
    private function lowStockAlerts(int $limit = 100): array
    {
        $rows = Db::name('lg_lin-trade_inventory')
            ->alias('i')
            ->join('lg_lin-trade_product p', 'p.id = i.product_id')
            ->where('p.status', 1)
            ->whereColumn('i.quantity', '>', 0)
            ->whereColumn('i.quantity', '<', 'p.safety_stock')
            ->field('i.product_id, p.name, p.product_no, i.warehouse_id, i.quantity, p.safety_stock')
            ->order('i.product_id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($rows as &$r) {
            $r['quantity']    = (float) $r['quantity'];
            $r['safety_stock'] = (float) $r['safety_stock'];
        }
        unset($r);
        if ($rows) {
            TradeEvents::publish(TradeEvents::LOW_STOCK_REMIND, ['count' => count($rows), 'items' => $rows]);
        }
        return $rows;
    }

    /** 呆滞扫描：现货>0 且连续 N 天无出库反馈（复用以账报口径）。 */
    private function dullStockScan(): array
    {
        $days = max(1, (int) $this->config('stock.dull_days', 90));
        $rows = App::make(ReportService::class)->dullStock($days);
        if ($rows) {
            TradeEvents::publish(TradeEvents::DULL_STOCK_REMIND, ['count' => count($rows), 'items' => $rows]);
        }
        return $rows;
    }

    /** 草稿到期前提醒：采集临近保留期的草稿单。 */
    private function draftReminders(): array
    {
        $rows = App::make(DraftService::class)->remindDrafts();
        if ($rows) {
            TradeEvents::publish(TradeEvents::DRAFT_REMIND, ['count' => count($rows), 'drafts' => $rows]);
        }
        return $rows;
    }

    /** 读取租户配置，缺失回退默认值。 */
    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}