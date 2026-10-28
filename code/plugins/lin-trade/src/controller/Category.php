<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\model\Category as CategoryModel;
use Lg\Pack\Trade\model\Product;
use think\facade\Request;
use think\Response;

/**
 * 商品分类档案控制器。
 *
 * 权限点：trade:category:view / trade:category:create / trade:category:edit
 * 负责商品分类列表（树/平铺）、新增/编辑、启用/停用与下拉选项。
 */
class Category extends TradeController
{
    /** GET /trade/category/index —— 分类档案页 */
    public function index(): Response
    {
        return $this->render('category/index');
    }

    /** GET /trade/category/rows —— 分页分类列表（平铺，带上级名） */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $query = CategoryModel::order('sort', 'asc')->order('id', 'asc');
        if ('' !== $args['keyword']) {
            $query->whereLike('name', "%{$args['keyword']}%");
        }
        if ('' !== $args['status'] && null !== $args['status']) {
            $query->where('status', $args['status']);
        }
        $total = $query->count();
        $list  = $query->page($args['page'], $args['pageSize'])
            ->field('id, name, parent_id, sort, status')
            ->select()->toArray();

        // 附带上级分类名称
        $parentIds = array_values(array_unique(array_filter(array_column($list, 'parent_id'))));
        $names     = [];
        if ($parentIds) {
            $names = CategoryModel::whereIn('id', $parentIds)->column('name', 'id');
        }
        foreach ($list as &$row) {
            $row['parent_name'] = $names[$row['parent_id']] ?? '';
        }
        unset($row);
        return $this->jsonOk(['total' => $total, 'list' => $this->withStatusLabels($list, 'common')]);
    }

    /** POST /trade/category/save —— 新增或更新分类 */
    public function save(): Response
    {
        try {
            $name = trim((string) Request::post('name', ''));
            if ('' === $name) {
                return $this->jsonFail('分类名称不能为空');
            }
            $id       = (int) Request::post('id', 0);
            $parentId = (int) Request::post('parent_id', 0);
            $sort     = (int) Request::post('sort', 0);
            // 禁止把分类挂到自己或子孙名下，简化为禁止 parent_id === id
            if ($id > 0 && $parentId === $id) {
                return $this->jsonFail('上级分类不能选择自身');
            }
            if ($id > 0) {
                CategoryModel::where('id', $id)->update(compact('name', 'parent_id', 'sort'));
                return $this->jsonOk(null, '保存成功');
            }
            CategoryModel::create(compact('name', 'parent_id', 'sort') + ['status' => 1]);
            return $this->jsonOk(null, '保存成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/category/switch —— 启用/停用分类（停用前校验无商品） */
    public function toggle(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $mode = (string) Request::post('mode', 'off');
            if ($id <= 0) {
                return $this->jsonFail('参数错误');
            }
            if ('on' !== $mode) {
                if (Product::where('category_id', $id)->count() > 0) {
                    return $this->jsonFail('该分类下存在商品，无法停用');
                }
            }
            CategoryModel::where('id', $id)->update(['status' => ('on' === $mode) ? 1 : 0]);
            return $this->jsonOk(null, '操作成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/category/options —— 启用分类下拉（供商品选择） */
    public function options(): Response
    {
        $list = CategoryModel::where('status', 1)
            ->order('sort', 'asc')->order('id', 'asc')
            ->field('id, name, parent_id')
            ->select()->toArray();
        return $this->jsonOk($list);
    }
}