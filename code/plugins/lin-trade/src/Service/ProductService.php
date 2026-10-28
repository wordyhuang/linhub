<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Category;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\Unit;
use think\facade\App;
use think\facade\Db;

/**
 * 商品档案服务（分类 / 单位 / 商品增删改查）。
 */
class ProductService
{
    // ---------------- 分类 ----------------

    public function createCategory(string $name, int $parentId = 0, int $sort = 0): Category
    {
        return Category::create(compact('name', 'parent_id', 'sort') + ['status' => 1]);
    }

    public function updateCategory(int $id, array $data): bool
    {
        return (bool) Category::where('id', $id)->update($data);
    }

    /**
     * 停用分类；若分类下存在商品则不允许停用。
     */
    public function disableCategory(int $id): bool
    {
        if (Product::where('category_id', $id)->count() > 0) {
            throw new \RuntimeException('该分类下存在商品，无法停用');
        }
        return (bool) Category::where('id', $id)->update(['status' => 0]);
    }

    public function categoryTree(): array
    {
        return Category::order('sort', 'asc')->select()->toArray();
    }

    // ---------------- 单位 ----------------

    public function createUnit(string $name): Unit
    {
        return Unit::create(compact('name') + ['status' => 1]);
    }

    public function unitList(string $status = ''): array
    {
        $query = Unit::order('id', 'asc');
        if ('' !== $status) {
            $query->where('status', $status);
        }
        return $query->select()->toArray();
    }

    // ---------------- 商品 ----------------

    /**
     * 新增商品。
     *
     * @param array $data product_no/name/spec/unit_id/category_id/is_production/safety_stock/reference_cost_price/reference_sale_price
     * @throws \RuntimeException 商品编码重复
     */
    public function create(array $data): Product
    {
        // 商品编码：按配置 trade.product_no.rule 决定自动前缀(SP)或手动录入
        $productNo = isset($data['product_no']) ? trim((string) $data['product_no']) : '';
        $rule      = $this->productNoRule();
        if ('' === $productNo && 'auto_prefix' === $rule) {
            $productNo = 'SP' . date('YmdHis') . mt_rand(1000, 9999);
        }
        if ('' === $productNo) {
            throw new \RuntimeException('商品编码不能为空（配置为手动录入时需提供）');
        }
        if (Product::where('product_no', $productNo)->find()) {
            throw new \RuntimeException('商品编码已存在：' . $productNo);
        }
        return Product::create([
            'product_no'           => $productNo,
            'name'                 => $data['name'],
            'spec'                 => $data['spec'] ?? null,
            'unit_id'              => $data['unit_id'] ?? null,
            'category_id'          => $data['category_id'] ?? null,
            'is_production'        => $data['is_production'] ?? 0,
            'safety_stock'         => $data['safety_stock'] ?? 0,
            'reference_cost_price' => $data['reference_cost_price'] ?? 0,
            'reference_sale_price' => $data['reference_sale_price'] ?? 0,
            'status'               => 1,
        ]);
    }

    /** 读取商品编码规则配置（trade.product_no.rule，默认 auto_prefix）。 */
    private function productNoRule(): string
    {
        try {
            /** @var \linhub\host\services\config\Config $config */
            $config = App::make('config');
            return (string) ($config->get('pack.lin-trade.product_no.rule', 'auto_prefix'));
        } catch (\Throwable $e) {
            return 'auto_prefix';
        }
    }

    public function update(int $id, array $data): bool
    {
        return (bool) Product::where('id', $id)->update($data);
    }

    /**
     * 停用商品；存在库存或未完成单据时不允许。
     */
    public function disable(int $id): bool
    {
        $total = Db::name('lg_lin-trade_inventory')->where('product_id', $id)->sum('quantity');
        if ((float) $total != 0) {
            throw new \RuntimeException('该商品仍有库存，无法停用');
        }
        return (bool) Product::where('id', $id)->update(['status' => 0]);
    }

    public function detail(int $id): ?array
    {
        $p = Product::where('id', $id)->find();
        return $p ? $p->toArray() : null;
    }

    /**
     * 分页查询商品列表。
     */
    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = Product::with(['unit', 'category'])->order('id', 'desc');
        if (!empty($cond['keyword'])) {
            $query->where(fn($q) => $q
                ->whereLike('name', "%{$cond['keyword']}%")
                ->whereOr('product_no', 'like', "%{$cond['keyword']}%"));
        }
        if (isset($cond['status']) && '' !== $cond['status']) {
            $query->where('status', $cond['status']);
        }
        if (!empty($cond['category_id'])) {
            $query->where('category_id', $cond['category_id']);
        }
        $total   = $query->count();
        $list    = $query->page($page, $pageSize)->select()->toArray();
        return ['total' => $total, 'list' => $list];
    }
}