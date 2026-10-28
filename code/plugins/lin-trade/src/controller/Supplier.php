<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\model\Supplier as SupplierModel;
use think\facade\Db;
use think\facade\Request;
use think\Response;

/**
 * 供应商档案控制器。
 *
 * 权限点：trade:supplier:view / trade:supplier:create / trade:supplier:edit
 * 负责供应商档案的列表分页、关键字搜索、新增/编辑、启用/停用与下拉选项。
 */
class Supplier extends TradeController
{
    /** GET /trade/supplier/index —— 供应商档案页 */
    public function index(): Response
    {
        return $this->render('supplier/index');
    }

    /** GET /trade/supplier/rows —— 分页供应商列表 */
    public function rows(): Response
    {
        $args = $this->pageArgs();
        $query = SupplierModel::order('id', 'desc');
        if ('' !== $args['keyword']) {
            $kw = $args['keyword'];
            $query->where(fn($q) => $q
                ->whereLike('name', "%{$kw}%")
                ->whereOr('supplier_no', 'like', "%{$kw}%")
                ->whereOr('contact', 'like', "%{$kw}%")
                ->whereOr('phone', 'like', "%{$kw}%"));
        }
        if ('' !== $args['status'] && null !== $args['status']) {
            $query->where('status', $args['status']);
        }
        $total = $query->count();
        $list  = $query->page($args['page'], $args['pageSize'])
            ->field('id, supplier_no, name, contact, phone, address, remark, settle_note, status')
            ->select()->toArray();
        return $this->jsonOk(['total' => $total, 'list' => $this->withStatusLabels($list, 'common')]);
    }

    /** POST /trade/supplier/save —— 新增或更新供应商 */
    public function save(): Response
    {
        try {
            $payload = Request::post();
            $name    = trim((string) ($payload['name'] ?? ''));
            if ('' === $name) {
                return $this->jsonFail('供应商名称不能为空');
            }
            $id = (int) ($payload['id'] ?? 0);
            if ($id > 0) {
                SupplierModel::where('id', $id)->update([
                    'name'        => $name,
                    'contact'     => (string) ($payload['contact'] ?? ''),
                    'phone'       => (string) ($payload['phone'] ?? ''),
                    'address'     => (string) ($payload['address'] ?? ''),
                    'remark'      => (string) ($payload['remark'] ?? ''),
                    'settle_note' => (string) ($payload['settle_note'] ?? ''),
                ]);
                return $this->jsonOk(null, '保存成功');
            }
            // 新增：生成供应商编号 SU + 递增序号
            $maxId = (int) SupplierModel::max('id');
            $no    = 'SU' . str_pad((string) ($maxId + 1), 4, '0', STR_PAD_LEFT);
            SupplierModel::create([
                'supplier_no' => $no,
                'name'        => $name,
                'contact'     => (string) ($payload['contact'] ?? ''),
                'phone'       => (string) ($payload['phone'] ?? ''),
                'address'     => (string) ($payload['address'] ?? ''),
                'remark'      => (string) ($payload['remark'] ?? ''),
                'settle_note' => (string) ($payload['settle_note'] ?? ''),
                'status'      => 1,
            ]);
            return $this->jsonOk(null, '保存成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** POST /trade/supplier/switch —— 启用/停用供应商 */
    public function toggle(): Response
    {
        try {
            $id   = (int) Request::post('id', 0);
            $mode = (string) Request::post('mode', 'off');
            $st   = ('on' === $mode) ? 1 : 0;
            if ($id <= 0) {
                return $this->jsonFail('参数错误');
            }
            SupplierModel::where('id', $id)->update(['status' => $st]);
            return $this->jsonOk(null, '操作成功');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/supplier/options —— 激活供应商下拉（供采购单选择） */
    public function options(): Response
    {
        $list = SupplierModel::where('status', 1)
            ->order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        return $this->jsonOk($list);
    }
}