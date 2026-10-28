<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\model\Unit as UnitModel;
use think\facade\Request;
use think\Response;

/**
 * 计量单位档案控制器。
 *
 * 权限点：trade:unit:view / trade:unit:create / trade:unit:edit
 * 负责计量单位列表、新增/编辑、启用/停用与下拉选项。
 */
class Unit extends TradeController
{
    /** GET /trade/unit/index —— 单位档案页 */
    public function index(): Response
    {
        return $this->render('unit/index');
    }

    /** GET /trade/unit/rows —— 分页单位列表 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $query = UnitModel::order('id', 'desc');
        if ('' !== $args['keyword']) {
            $query->whereLike('name', "%{$args['keyword']}%");
        }
        if ('' !== $args['status'] && null !== $args['status']) {
            $query->where('status', $args['status']);
        }
        $total = $query->count();
        $list  = $query->page($args['page'], $args['pageSize'])
            ->field('id, name, status')
            ->select()->toArray();
        return $this->jsonOk(['total' => $total, 'list' => $this->withStatusLabels($list, 'common')]);
    }

    /** POST /trade/unit/save —— 新增或更新单位 */
    public function save(): Response
    {
        try {
            $name = trim((string) Request::post('name', ''));
            if ('' === $name) {
                return $this->jsonFail('单位名称不能为空');
            }
            $id = (int) Request::post('id', 0);
            if ($id > 0) {
                UnitModel::where('id', $id)->update(['name' => $name]);
                return $this->jsonOk(null, '保存成功');
            }
            UnitModel::create(['name' => $name, 'status' => 1]);
            return $this->jsonOk(null, '保存成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/unit/switch —— 启用/停用单位 */
    public function toggle(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $mode = (string) Request::post('mode', 'off');
            if ($id <= 0) {
                return $this->jsonFail('参数错误');
            }
            UnitModel::where('id', $id)->update(['status' => ('on' === $mode) ? 1 : 0]);
            return $this->jsonOk(null, '操作成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/unit/options —— 激活单位下拉（供商品选择） */
    public function options(): Response
    {
        $list = UnitModel::where('status', 1)
            ->order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        return $this->jsonOk($list);
    }
}