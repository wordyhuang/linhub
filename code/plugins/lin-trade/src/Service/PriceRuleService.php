<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Customer;
use Lg\Pack\Trade\model\PriceApproval;
use Lg\Pack\Trade\model\PriceRule;
use Lg\Pack\Trade\model\Product;
use think\facade\App;
use think\facade\Db;

/**
 * 价格体系服务（PRD 3.8）。
 *
 * 带价优先级（手工改价 > 促销价 > 最近成交价 > 等级价 > 参考售价）：
 *  - 手工改价：销售单创建时手工录入单价，优先级最高；
 *  - 促销价：trade:price:manage 维护"新价+起止日期"，促销期内优先级最高（低于最低售价不触发审批）；
 *  - 最近成交价：trade.price.recent_backfill=on 时自动回填该客户该商品最近一次成交单价（优先于等级价）；
 *  - 等级价：trade.price.level_price=on 时按客户 price_level 取等级价；
 *  - 参考售价：兜底取商品参考售价。
 *
 * 最低售价控制（trade.price.min_price_control=on）：手工改价/参考售价低于最低售价时，
 * 销售提交需走 trade:price:approve 审批留痕（lg_lin-trade_price_approval），审批通过才可提交。
 * P0 阶段最小控制面 = 手工改价 > 参考售价 两条主路径。
 */
class PriceRuleService
{
    /** 带价来源标识 */
    public const SRC_MANUAL    = 'manual';     // 手工改价
    public const SRC_PROMO     = 'promo';      // 促销价
    public const SRC_RECENT    = 'recent';     // 最近成交价
    public const SRC_LEVEL     = 'level';      // 等级价
    public const SRC_REFERENCE = 'reference';  // 参考售价

    // 促销价是否处于生效期：start<=today<=end
    private const PROMO_ONLY_CHECK = true;

    /**
     * 解析带价（PRD 3.8.2 带价优先级 / 3.8.3 促销价 / 3.8.5 自动回填）。
     *
     * @param int|null $manualPrice 手工改价（销售单行录入单价，为空则走自动带价）
     * @return array{price:float, source:string, min_price:float, need_approval:bool}
     */
    public function resolvePrice(int $productId, ?int $customerId = null, ?float $manualPrice = null): array
    {
        $rule    = PriceRule::where('product_id', $productId)->find();
        $product = Product::find($productId);

        $minPrice = $rule ? (float) $rule->min_price : (float) ($product ? $product->reference_sale_price : 0) * 0.9;
        $source   = self::SRC_REFERENCE;
        $price    = (float) ($product ? $product->reference_sale_price : 0);

        // 1) 手工改价（优先级最高）
        if (null !== $manualPrice && (float) $manualPrice > 0) {
            $price  = round((float) $manualPrice, 4);
            $source = self::SRC_MANUAL;
        } elseif ($rule && self::PROMO_ONLY_CHECK && isset($rule->promo_price)
            && (float) $rule->promo_price > 0
            && $this->inPromoPeriod($rule->promo_start, $rule->promo_end)) {
            // 2) 促销价：促销期内优先级最高，低于最低售价不触发审批（PRD 3.8.3）
            $price  = (float) $rule->promo_price;
            $source = self::SRC_PROMO;
        } elseif ('on' === (string) $this->config('price.recent_backfill', 'on') && $customerId
            && (($recent = $this->recentDealPrice($customerId, $productId)) !== null)) {
            // 3) 最近成交价：优先于等级价（PRD 3.8.5）
            $price  = $recent;
            $source = self::SRC_RECENT;
        } elseif ('on' === (string) $this->config('price.level_price', 'on') && $customerId && $rule) {
            // 4) 等级价：取客户 price_level 对应规则
            $level = $this->customerPriceLevel($customerId);
            $levelRule = PriceRule::where('product_id', $productId)
                ->where('level_code', $level)->find();
            if ($levelRule && (float) $levelRule->level_price > 0) {
                $price  = (float) $levelRule->level_price;
                $source = self::SRC_LEVEL;
            }
        }

        // 最低售价审批标记（PRD 3.8.1）：min_price_control=on 且 非促销价 低于最低售价时需审批
        $needApproval = 'on' === (string) $this->config('price.min_price_control', 'on')
            && self::SRC_PROMO !== $source
            && $minPrice > 0
            && (float) $price < $minPrice;

        return [
            'price'         => round($price, 4),
            'source'        => $source,
            'min_price'     => round($minPrice, 4),
            'need_approval' => $needApproval,
        ];
    }

    /**
     * 维护价格规则（PRD 3.8.2）
     *
     * @param int    $productId 商品 id
     * @param string $levelCode 客户等级（default/wholesale/vip，必须属于 trade.price.levels）
     * @param float  $levelPrice 等级价
     * @param float  $minPrice   最低售价（缺省取 参考售价×90%）
     */
    public function setLevelPrice(int $productId, string $levelCode, float $levelPrice, ?float $minPrice = null): PriceRule
    {
        $levels = explode(',', (string) $this->config('price.levels', 'default,wholesale,vip'));
        if (!in_array($levelCode, $levels, true)) {
            throw new \RuntimeException("客户等级[{$levelCode}]不在配置 trade.price.levels 集合内");
        }
        if (count($levels) > 6) {
            throw new \RuntimeException('客户等级配置最多支持 6 档');
        }

        $rule = PriceRule::where('product_id', $productId)
            ->where('level_code', $levelCode)->find();
        if (null === $minPrice) {
            $minPrice = (float) Product::where('id', $productId)->value('reference_sale_price') * 0.9;
        }

        if ($rule) {
            $rule->level_price = round($levelPrice, 4);
            $rule->min_price   = round($minPrice, 4);
            $rule->save();
            return $rule;
        }
        $rule = PriceRule::create([
            'product_id'  => $productId,
            'level_code'  => $levelCode,
            'level_price' => round($levelPrice, 4),
            'min_price'   => round($minPrice, 4),
        ]);
        return $rule;
    }

    /**
     * 维护促销价（PRD 3.8.3：促销价 = 新价 + 起止日期，促销期内带价优先级最高）。
     * 促销价写在该商品 default 档规则的 promo_price/promo_start/promo_end。
     *
     * @param int          $productId  商品 id
     * @param float        $promoPrice 促销价
     * @param string|null  $start      开始日期 Y-m-d
     * @param string|null  $end        结束日期 Y-m-d
     */
    public function setPromotion(int $productId, float $promoPrice, ?string $start = null, ?string $end = null): PriceRule
    {
        if ($promoPrice <= 0) {
            throw new \RuntimeException('促销价必须大于 0');
        }
        if ($start && $end && $start > $end) {
            throw new \RuntimeException('促销结束日期不能早于开始日期');
        }

        $rule = PriceRule::where('product_id', $productId)
            ->where('level_code', 'default')->find();
        if (!$rule) {
            $minPrice = (float) Product::where('id', $productId)->value('reference_sale_price') * 0.9;
            $rule = PriceRule::create([
                'product_id'  => $productId,
                'level_code'  => 'default',
                'level_price' => 0,
                'min_price'   => round($minPrice, 4),
            ]);
        }
        $rule->promo_price = round($promoPrice, 4);
        $rule->promo_start = $start;
        $rule->promo_end   = $end;
        $rule->save();
        return $rule;
    }

    /**
     * 最低售价审批（PRD 3.8.1，trade:price:approve 审批留痕）。
     *
     * @param int $approvalId 待审批记录 id
     * @param bool $approve   true=通过 false=驳回
     * @param int  $approverId 审批人 user id
     * @param string|null $reason 审批理由/备注
     */
    public function approveLowPrice(int $approvalId, bool $approve, int $approverId, ?string $reason = null): bool
    {
        $approval = PriceApproval::find($approvalId);
        if (!$approval) {
            throw new \RuntimeException('价格审批记录不存在');
        }
        if ('pending' !== $approval->status) {
            throw new \RuntimeException('该审批已被处理，当前状态：' . $approval->status);
        }
        return (bool) PriceApproval::where('id', $approvalId)->update([
            'status'     => $approve ? 'approved' : 'rejected',
            'approver_id'=> $approverId,
            'reason'     => $reason,
        ]);
    }

    /**
     * 审批通过判定：销售提交时校验该单所有低于最低售价的行均已通过审批（PRD 3.8.1）。
     * 任一待审批(rejected/pending)即不可提交；若无审批需求则视为通过。
     *
     * @param int $saleOrderId
     */
    public function assertApprovedForSubmit(int $saleOrderId): void
    {
        $pending = PriceApproval::where('sale_order_id', $saleOrderId)
            ->whereIn('status', ['pending', 'rejected'])->count();
        if ($pending > 0) {
            throw new \RuntimeException("销售单[{$saleOrderId}]存在 {$pending} 条未通过的最低售价审批，无法提交");
        }
    }

    /**
     * 查询某客户对某商品最近一次成交单价（最近出库单的行单价，PRD 3.8.5）。
     *
     * @return float|null 无成交记录返回 null
     */
    public function recentDealPrice(int $customerId, int $productId): ?float
    {
        $price = Db::name('lg_lin-trade_sale_order_item')
            ->alias('si')
            ->join('lg_lin-trade_sale_order so', 'so.id = si.order_id')
            ->where('so.customer_id', $customerId)
            ->where('si.product_id', $productId)
            ->where('si.price', '>', 0)
            ->order('so.id', 'desc')
            ->value('si.price');
        return null === $price ? null : (float) $price;
    }

    // ---------------- 内部 ----------------

    /** 取客户价格等级（PRD 3.8.2 客户 price_level） */
    private function customerPriceLevel(int $customerId): string
    {
        $level = Customer::where('id', $customerId)->value('price_level');
        return $level ?: 'default';
    }

    /** 是否处于促销生效期（含开闭区间）；任一端为空视为不设限 */
    private function inPromoPeriod(?string $start, ?string $end): bool
    {
        $today = date('Y-m-d');
        if (!empty($start) && $today < $start) {
            return false;
        }
        if (!empty($end) && $today > $end) {
            return false;
        }
        return true;
    }

    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}