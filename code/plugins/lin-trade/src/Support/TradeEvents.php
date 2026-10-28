<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Support;

use linhub\host\communication\HostServiceApi;
use think\facade\App;

/**
 * 林销事件发布帮助类。
 *
 * 事件主题统一带 `lg.trade.` 前缀（协议 N5）。宿主服务 id：messaging。
 * 所有对外事件在业务服务落库并提交事务后调用本类发布，保证"先落库后广播"。
 *
 * 发布主题列表（顶事件）：
 *  - lg.trade.product.created        商品建档
 *  - lg.trade.purchase.inbound       采购入库完成
 *  - lg.trade.purchase_order.audit   采购审核（提交）通过
 *  - lg.trade.sale_order.created     销售订单创建
 *  - lg.trade.sale.order.done        销售订单完成出库
 *  - lg.trade.sale_order.outbound    销售出库发生（PRD 5.2）
 *  - lg.trade.inventory.changed      库存变动（通用，携带变动明细）
 *  - lg.trade.purchase_return.confirmed 采购退货完成（库存扣减完成，V_1.1）
 *  - lg.trade.sale_return.confirmed     销售退货完成（库存回补完成，V_1.1）
 *  - lg.trade.credit.exceeded           客户信用超额（alert 模式出库时，V_1.1）
 *  - lg.trade.reconciliation.generated  客户/供应商对账单生成（V_1.1）
 */
class TradeEvents
{
    public const PRODUCT_CREATED     = 'lg.trade.product.created';
    public const PURCHASE_INBOUND    = 'lg.trade.purchase.inbound';
    public const PURCHASE_ORDER_AUDIT = 'lg.trade.purchase_order.audit';
    public const SALE_ORDER_CREATED  = 'lg.trade.sale_order.created';
    public const SALE_OUTBOUND       = 'lg.trade.sale_order.outbound';
    public const SALE_ORDER_DONE     = 'lg.trade.sale.order.done';
    public const INVENTORY_CHANGED   = 'lg.trade.inventory.changed';
    // V_1.1 新增事件
    public const PURCHASE_RETURN_CONFIRMED = 'lg.trade.purchase_return.confirmed';
    public const SALE_RETURN_CONFIRMED     = 'lg.trade.sale_return.confirmed';
    public const CREDIT_EXCEEDED           = 'lg.trade.credit.exceeded';
    public const RECONCILIATION_GENERATED  = 'lg.trade.reconciliation.generated';
    // 定时任务提醒 / ABC 重算（每日 08:00 / 每月 1 日）
    public const ARRIVAL_REMIND            = 'lg.trade.remind.arrival';
    public const OVERDUE_REMIND            = 'lg.trade.remind.overdue';
    public const LOW_STOCK_REMIND          = 'lg.trade.remind.low_stock';
    public const DULL_STOCK_REMIND         = 'lg.trade.remind.dull_stock';
    public const DRAFT_REMIND              = 'lg.trade.remind.draft';
    public const ABC_CALCULATED            = 'lg.trade.abc.calculated';

    /** 发布事件。失败仅记录，不阻断主流程。 */
    public static function publish(string $topic, array $payload = []): void
    {
        try {
            /** @var \linhub\host\services\messaging\Messaging $messaging */
            $messaging = HostServiceApi::getService(App::getInstance(), 'messaging', 'lin-trade');
            $messaging->publish($topic, $payload);
        } catch (\Throwable $e) {
            // 事件总线不可用时降级为静默，避免影响核心业务
            error_log('[trade] event publish failed: ' . $e->getMessage());
        }
    }
}