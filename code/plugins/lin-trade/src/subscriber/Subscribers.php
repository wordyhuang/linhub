<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\subscriber;

use linhub\host\communication\broker\Event;
use Lg\Pack\Trade\Service\CustomerCreditService;
use Lg\Pack\Trade\Service\SaleService;
use think\facade\App;

/**
 * 跨包事件订阅器（PRD 5.3 跨产品联动）。
 *
 * 宿主 messaging 服务已就绪、M008 已放行外部主题：
 *  - manifest.subscribes 正式声明 7 个外部主题订阅（宿主启动时由 PackageRouteLoader 汇总装配）；
 *  - 本类为唯一 handler：实现宿主标准契约「无参构造 + handle(Event $event)」，
 *    handle 内按 event->topic 分发到各处理方法。
 * 说明文档见 README「跨包联动」。
 */
class Subscribers
{
    /** 主题 => 处理方法名（handle 分发用，与 manifest.subscribes 一一对应）。 */
    private const ROUTES = [
        'lg.crm.deal.closed'          => 'onCrmDealClosed',
        'lg.production.issue.outbound' => 'onProductionIssueOutbound',
        'lg.production.issue.return'   => 'onProductionIssueReturn',
        'lg.quality.iqc.passed'        => 'onIqcPassed',
        'lg.quality.iqc.failed'        => 'onIqcFailed',
        // V_1.1 新增：客户建档初始化信用额度 / 应收结清释放信用占用
        'lg.crm.customer.created'      => 'onCustomerCreated',
        'lg.finance.receivable.status' => 'onReceivableStatus',
    ];

    /**
     * 连接宿主事件总线：注册本包对上下游业务事件的监听。
     *
     * 由 Package::boot() 调用（运行时主动装配路径；manifest.subscribes 为宿主装配的权威声明）。
     *
     * @param callable $messaging 宿主 messaging 服务（具备 subscribe($topic,$handler)）
     */
    public function register(callable $messaging): void
    {
        foreach (array_keys(self::ROUTES) as $topic) {
            $messaging($topic, self::class);
        }
        // 本包主动对外：库存变动已通过顶层事件广播（见 TradeEvents）
    }

    /**
     * 宿主标准契约入口（HandlerCaller）：按主题分发到处理方法。
     * 绝不向宿主抛异常——分发失败仅记录，保证不影响发布方与其他订阅者。
     */
    public function handle(Event $event): void
    {
        $method = self::ROUTES[$event->topic] ?? null;
        if ($method === null) {
            return;
        }
        try {
            $this->$method($event->payload);
        } catch (\Throwable $e) {
            error_log('[trade] subscriber ' . $event->topic . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * 处理客户成交事件 -> 创建销售订单草稿。
     * payload: { deal_id, customer_id, product_id, quantity, price }
     */
    public function onCrmDealClosed(array $payload): void
    {
        // 示例实现：转销售草稿，交由销售确认后出库
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $productId  = (int) ($payload['product_id'] ?? 0);
        $qty        = (float) ($payload['quantity'] ?? 0);
        $price      = (float) ($payload['price'] ?? 0);
        if (!$customerId || !$productId || $qty <= 0) {
            return;
        }
        /** @var \Lg\Pack\Trade\Service\SaleService $sale */
        $sale = app(SaleService::class);
        $sale->create([
            'customer_id'  => $customerId,
            'warehouse_id' => 0, // TODO: 由默认出库仓库策略决定
            'items'        => [['product_id' => $productId, 'quantity' => $qty, 'price' => $price]],
        ], 0, 'lg.crm.deal.closed');
    }

    /**
     * 处理生产下料/领料出库事件 -> 按生产工单扣减库存。
     * payload: { issue_id, production_id, product_id, quantity, warehouse_id, work_order_id }
     */
    public function onProductionIssueOutbound(array $payload): void
    {
        // TODO: 二期对接林造，按工单下料扣减库存（type=sale_out 或专用 production_issue_out）
    }

    /**
     * 处理生产退料入库事件 -> 余料退回仓库。
     * payload: { return_id, production_id, product_id, quantity, warehouse_id }
     */
    public function onProductionIssueReturn(array $payload): void
    {
        // TODO: 二期对接林造，退料增加仓库库存（type=return_in）
    }

    /**
     * 处理来料质检通过事件 -> 置明细为待入库（辅助采购入库）。
     * payload: { check_id, purchase_id, order_id, product_id, quantity }
     */
    public function onIqcPassed(array $payload): void
    {
        // TODO: 二期对接林检，联动采购单由待检转到可入库
    }

    /**
     * 处理来料质检不合格事件 -> 触发采购退货。
     * payload: { check_id, order_id, product_id, quantity, warehouse_id }
     */
    public function onIqcFailed(array $payload): void
    {
        // TODO: 二期对接林检，调用 PurchaseService::return 完成采购退货
    }

    /**
     * 处理客户建档事件 -> 初始化客户信用额度（PRD 3.9.1）。
     * payload: { customer_id, credit_limit?, operator? }；额度缺省取 trade.credit.default_limit。
     */
    public function onCustomerCreated(array $payload): void
    {
        $customerId = (int) ($payload['customer_id'] ?? 0);
        if (!$customerId) {
            return;
        }
        $operator = (int) ($payload['operator'] ?? 0) ?: null;
        if (isset($payload['credit_limit'])) {
            $limit = (float) $payload['credit_limit'];
        } else {
            // 缺省取 trade.credit.default_limit
            $limit = (float) App::make('config')->get('pack.lin-trade.credit.default_limit', 0);
        }

        /** @var \Lg\Pack\Trade\Service\CustomerCreditService $credit */
        $credit = app(CustomerCreditService::class);
        $credit->setLimit($customerId, $limit, $operator, true);
    }

    /**
     * 处理应收结清事件 -> 释放客户信用占用（PRD 3.9.2 结清来源）。
     * payload: { status, customer_id, sale_order_id|order_id, amount, operator? }
     * 仅在 status 为已结清（settled/paid/success）时释放。
     */
    public function onReceivableStatus(array $payload): void
    {
        $status = (string) ($payload['status'] ?? '');
        if (!in_array($status, ['settled', 'paid', 'success'], true)) {
            return;
        }
        $customerId  = (int) ($payload['customer_id'] ?? 0);
        $saleOrderId = (int) ($payload['sale_order_id'] ?? $payload['order_id'] ?? 0);
        if (!$customerId || !$saleOrderId) {
            return;
        }
        $amount   = (float) ($payload['amount'] ?? 0);
        $operator = (int) ($payload['operator'] ?? 0) ?: null;

        /** @var \Lg\Pack\Trade\Service\CustomerCreditService $credit */
        $credit = app(CustomerCreditService::class);
        $credit->settleFromReceivable($customerId, $saleOrderId, $amount, $operator);
    }
}