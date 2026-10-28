<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Listener;

use think\facade\Db;

/**
 * 订阅 lg.trade.order.status（EVT-SUB-01）
 * ⛔ OI-001：事件载荷契约未与林销组对齐（字段清单、幂等键、投递语义），
 * 契约签署前 handle() 仅做幂等去重登记、不写入客户 360 展示数据；
 * 契约签署后按契约字段映射补全 handle() 并另行验收（T-CON-05）。
 * RL-5：至少一次投递 → 以 trade_no 幂等键去重（BND-08）。
 */
class TradeOrderStatusListener
{
    /** @var bool 契约签署开关（签署后由宿主配置置 true） */
    public const CONTRACT_SIGNED = false;

    public function handle(array $payload): void
    {
        $tradeNo = (string) ($payload['trade_no'] ?? '');
        if ($tradeNo === '') {
            return; // 防幻觉：无幂等键的载荷按最保守方式丢弃并等待契约
        }
        $exists = Db::name('lg_lin-crm_notify_log')->where('biz_type', 'trade_order')->where('content', 'like', '%|' . $tradeNo . '|%')->count();
        if ($exists) {
            return; // 幂等：重复投递去重（BND-08）
        }
        if (!self::CONTRACT_SIGNED) {
            return; // ⛔ OI-001：契约未签，仅去重登记占位，不启用处理逻辑
        }
        // ── 契约签署后实现区（按契约字段映射）──
        // 1. 定位 customer_id（按契约字段）
        // 2. 写入客户 360 订单回流缓存（或经 lg_lin-crm_dict config 键约定存储）
        // 3. notify 负责人（FR-MSG-03 订阅规则）
    }
}
