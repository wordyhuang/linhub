<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\PriceRuleService;
use Lg\Pack\Trade\Service\ProductService;
use Lg\Pack\Trade\model\PriceApproval;
use Lg\Pack\Trade\model\PriceRule;
use Lg\Pack\Trade\model\Product as ProductModel;
use think\facade\Db;
use think\facade\Request;
use think\Response;

/**
 * 商品管理控制器。
 *
 * 权限点：trade:product:view / trade:product:create / trade:product:edit / trade:product:delete
 * 价格体系（V_1.1）：trade:price:view / trade:price:manage / trade:price:approve
 */
class Product extends TradeController
{
    public function __construct(
        private ProductService $service,
        private PriceRuleService $price
    ) {
    }

    /** GET /trade/product/index —— 商品管理页 */
    public function index(): Response
    {
        return $this->render('product/index');
    }

    /** GET /trade/product/rows —— 分页商品列表 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $data = $this->service->page([
            'keyword'     => $args['keyword'],
            'status'      => $args['status'],
            'category_id' => (int) $this->arg('category_id', 0),
        ], $args['page'], $args['pageSize']);
        $data['list'] = $this->withStatusLabels($data['list'], 'common');
        return $this->jsonOk($data);
    }

    /** POST /trade/product/save —— 新增或更新商品 */
    public function save(): Response
    {
        try {
            $payload = Request::post();
            $id      = (int) ($payload['id'] ?? 0);
            if ($id > 0) {
                $this->service->update($id, $this->pick($payload));
            } else {
                $this->service->create($payload);
            }
            return $this->jsonOk(null, '保存成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/product/switch —— 停用/启用商品 */
    public function toggle(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $mode = (string) Request::post('mode', 'off');
            if ('on' === $mode) {
                $this->service->update($id, ['status' => 1]);
            } else {
                $this->service->disable($id);
            }
            return $this->jsonOk(null, '操作成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/product/options —— 激活商品下拉（供采购/销售/调拨单选商品），可带参考价与指定仓库存 */
    public function options(): Response
    {
        $list = ProductModel::where('status', 1)
            ->with(['unit'])
            ->order('id', 'asc')
            ->field('id, name, spec, unit_id, is_production, reference_cost_price, reference_sale_price')
            ->select()->toArray();
        // 归一化输出：unit_name 取自关联单位
        foreach ($list as &$item) {
            $item['unit_name'] = ($item['unit']['name'] ?? '') ?: '';
            unset($item['unit']);
        }
        unset($item);

        // 可选带出指定仓库的实时库存（开单选品时参考，缺省不查）
        $wh = (int) Request::get('warehouse_id', 0);
        if ($wh > 0) {
            $qtyMap = Db::name('lg_lin-trade_inventory')
                ->where('warehouse_id', $wh)
                ->column('quantity', 'product_id');
            foreach ($list as &$item) {
                $item['stock_qty'] = (float) ($qtyMap[$item['id']] ?? 0);
            }
            unset($item);
        }
        return $this->jsonOk($list);
    }

    // ---------------- 价格体系（V_1.1，PRD 3.8） ----------------

    /** GET /trade/product/price —— 价格体系页 */
    public function price(): Response
    {
        return $this->render('product/price');
    }

    /** GET /trade/product/priceRules —— 价格规则列表 + 待审批价格 */
    public function priceRules(): Response
    {
        $rules = PriceRule::order('product_id', 'asc')->select()->toArray();
        $products = ProductModel::order('id', 'asc')
            ->field('id, name, product_no, reference_cost_price, reference_sale_price, status')
            ->select()->toArray();
        $approvals = PriceApproval::where('status', 'pending')->order('id', 'desc')
            ->select()->toArray();
        return $this->jsonOk([
            'rules'     => $rules,
            'products'  => $this->withStatusLabels($products, 'common'),
            'approvals' => $this->withStatusLabels($approvals, 'price_approval'),
        ]);
    }

    /** POST /trade/product/priceResolve —— 按带价优先级解析价格 */
    public function priceResolve(): Response
    {
        try {
            $res = $this->price->resolvePrice(
                (int) Request::post('product_id', 0),
                (int) Request::post('customer_id', 0) ?: null
            );
            return $this->jsonOk($res);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/product/priceSet —— 维护价格规则（等级价/促销价/最低售价） */
    public function priceSet(): Response
    {
        try {
            $productId = (int) Request::post('product_id', 0);
            $level     = (string) Request::post('level_code', 'default');
            $levelPrice = (float) Request::post('level_price', 0);
            $minPrice   = Request::post('min_price', null);
            $this->price->setLevelPrice(
                $productId, $level, $levelPrice,
                (null !== $minPrice && '' !== $minPrice) ? (float) $minPrice : null
            );
            // 促销价（可空）
            $promo = Request::post('promo_price', null);
            if (null !== $promo && '' !== $promo) {
                $this->price->setPromotion(
                    $productId, (float) $promo,
                    (string) Request::post('promo_start', '') ?: null,
                    (string) Request::post('promo_end', '') ?: null
                );
            }
            return $this->jsonOk(null, '价格规则已保存');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/product/priceApprove —— 最低售价审批 */
    public function priceApprove(): Response
    {
        try {
            $approvalId = (int) Request::post('approval_id', 0);
            $approve    = 'approve' === (string) Request::post('action', 'approve');
            $this->price->approveLowPrice(
                $approvalId, $approve, $this->uid(),
                (string) Request::post('reason', '') ?: null
            );
            return $this->jsonOk(null, $approve ? '已审批通过' : '已驳回');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** 提取可写字段，避免多余字段注入 */
    private function pick(array $p): array
    {
        $keys = ['name', 'spec', 'unit_id', 'category_id', 'is_production', 'safety_stock', 'reference_cost_price', 'reference_sale_price'];
        $out  = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        return $out;
    }
}