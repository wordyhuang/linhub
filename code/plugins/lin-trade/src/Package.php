<?php

declare(strict_types=1);

namespace Lg\Pack\Trade;

use Lg\Pack\Trade\Service\InventoryService;
use Lg\Pack\Trade\Service\DocNumberService;
use Lg\Pack\Trade\Service\ProductService;
use Lg\Pack\Trade\Service\PurchaseService;
use Lg\Pack\Trade\Service\PurchaseReturnService;
use Lg\Pack\Trade\Service\ReportService;
use Lg\Pack\Trade\Service\SaleService;
use Lg\Pack\Trade\Service\SaleReturnService;
use Lg\Pack\Trade\Service\StocktakeService;
use Lg\Pack\Trade\Service\TransferService;
use Lg\Pack\Trade\Service\PriceRuleService;
use Lg\Pack\Trade\Service\CustomerCreditService;
use Lg\Pack\Trade\Service\ReconciliationService;
use Lg\Pack\Trade\Service\ImportService;
use Lg\Pack\Trade\Service\DraftService;
use Lg\Pack\Trade\Service\DocQueryService;
use Lg\Pack\Trade\Service\ScheduledTaskService;
use Lg\Pack\Trade\subscriber\Subscribers;
use linhub\host\communication\HostServiceApi;
use think\Service;

/**
 * 功能包入口类（协议规范第 3 节，可选生命周期钩子宿主）。
 *
 * 装配由 S3 迁移 + S4 接入点统一完成；本入口负责：
 *  - 将业务服务注册为宿主服务（对应 manifest.services.provides：trade.inventory）；
 *  - 暴露各业务 Service 供包内/宿主容器解析；
 *  - 提供 install/upgrade/rollback 生命周期钩子占位。
 *
 * 跨包订阅（5.3）：M008 已放行外部主题，manifest.subscribes 已正式声明，
 * 宿主启动时由 PackageRouteLoader 汇总装配；本入口 boot() 的主动注册为幂等互补路径。
 */
class Package extends Service
{
    public function register(): void
    {
        // 核心库存服务：对宿主暴露为 trade.inventory
        $this->app->bind('trade.inventory', InventoryService::class);

        // 业务服务注册到包内容器，供控制器按类型自动解析
        $this->app->bind(InventoryService::class, InventoryService::class);
        $this->app->bind(DocNumberService::class, DocNumberService::class);
        $this->app->bind(ProductService::class, ProductService::class);
        $this->app->bind(PurchaseService::class, PurchaseService::class);
        $this->app->bind(PurchaseReturnService::class, PurchaseReturnService::class);
        $this->app->bind(SaleService::class, SaleService::class);
        $this->app->bind(SaleReturnService::class, SaleReturnService::class);
        $this->app->bind(StocktakeService::class, StocktakeService::class);
        $this->app->bind(TransferService::class, TransferService::class);
        $this->app->bind(ReportService::class, ReportService::class);
        // V_1.1 价格体系 / 客户信用服务
        $this->app->bind(PriceRuleService::class, PriceRuleService::class);
        $this->app->bind(CustomerCreditService::class, CustomerCreditService::class);
        // V_1.1 对账单 / 批量导入服务
        $this->app->bind(ReconciliationService::class, ReconciliationService::class);
        $this->app->bind(ImportService::class, ImportService::class);
        $this->app->bind(DraftService::class, DraftService::class);
        // V_1.1 单据查询服务（供林账对账）：对外暴露为 trade.doc_query
        $this->app->bind('trade.doc_query', DocQueryService::class);
        $this->app->bind(DocQueryService::class, DocQueryService::class);
        // V_1.1 支撑层定时任务执行体
        $this->app->bind(ScheduledTaskService::class, ScheduledTaskService::class);
    }

    public function boot(): void
    {
        // 跨包订阅运行时装配（与 manifest.subscribes 幂等互补：宿主启动时已按 manifest 汇总装配）
        $this->registerSubscribers(true);
        // 指示宿主在存在调度器实现时注册周期任务；宿主 scheduler 仅接口时安全降级
        $this->registerScheduledTasks();
    }

    /**
     * 周期任务注册（guard 式）。
     *
     * 宿主任务调度器（TaskScheduler 契约，M8）仅当存在实现时才注册，否则 error_log 降级，
     * 不阻断包启动；执行体由 ScheduledTaskService 承载，宿主/外部 cron 亦可直接触发。
     */
    protected function registerScheduledTasks(): void
    {
        try {
            $scheduler = HostServiceApi::getService($this->app, 'scheduler');
            if (null === $scheduler || !is_callable([$scheduler, 'schedule'])) {
                error_log('[trade] scheduler unavailable, scheduled tasks skipped');
                return;
            }
            // 每日 08:00 各提醒/预警扫描；每月 1 日 08:00 ABC 重算
            $scheduler->schedule('trade.daily', '0 8 * * *', ScheduledTaskService::class);
            $scheduler->schedule('trade.monthly_abc', '0 8 1 * *', ScheduledTaskService::class);
        } catch (\Throwable $e) {
            // 调度器不可用或注入失败时降级，不影响包启动
            error_log('[trade] register scheduled tasks failed: ' . $e->getMessage());
        }
    }

    /**
     * 订阅器装配入口。
     *
     * @param bool $enabled 是否实际连接宿主事件总线（M008 已放行外部主题，默认启用）
     */
    protected function registerSubscribers(bool $enabled = true): void
    {
        if (!$enabled) {
            return;
        }
        try {
            $subscribers = new Subscribers();
            $messaging   = fn($topic, $handler) => $this->messaging()->subscribe($topic, $handler);
            $subscribers->register($messaging);
        } catch (\Throwable $e) {
            // 事件总线不可用时静默降级，不影响包启动；manifest.subscribes 仍由宿主装配
            error_log('[trade] subscriber register failed: ' . $e->getMessage());
        }
    }

    /** 获取宿主消息/事件总线服务（host service id: messaging），经宿主统一门面解析。 */
    private function messaging()
    {
        return HostServiceApi::inject($this->app, ['messaging'])['messaging'];
    }
}