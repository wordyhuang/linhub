<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\DraftService;
use Lg\Pack\Trade\Service\ImportService;
use Lg\Pack\Trade\Service\PurchaseService;
use Lg\Pack\Trade\Service\SaleService;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\Warehouse;
use think\facade\Request;
use think\Response;

/**
 * 工具中心控制器（V_1.1，PRD 3.6）。
 *
 * 权限点：trade:import:execute / trade:draft:manage
 */
class Toolbox extends TradeController
{
    public function __construct(
        private ImportService $import,
        private DraftService $draft,
        private SaleService $sale,
        private PurchaseService $purchase
    ) {
    }

    /** GET /trade/toolbox/index —— 工具中心入口页 */
    public function index(): Response
    {
        return $this->render('toolbox/index');
    }

    // ---------------- 批量导入 ----------------

    /** GET /trade/toolbox/import —— 批量导入页（期初库存） */
    public function import(): Response
    {
        return $this->render('toolbox/import');
    }

    /** GET /trade/toolbox/importOptions —— 导入模板选项（商品/仓库下拉） */
    public function importOptions(): Response
    {
        $products = Product::order('id', 'asc')
            ->field('id, name, product_no, reference_cost_price')
            ->select()->toArray();
        $warehouses = Warehouse::order('id', 'asc')
            ->field('id, name')
            ->select()->toArray();
        return $this->jsonOk(compact('products', 'warehouses'));
    }

    /** POST /trade/toolbox/importSubmit —— 执行期初库存导入 */
    public function importSubmit(): Response
    {
        try {
            $rows = json_decode(Request::post('rows', '[]'), true) ?: [];
            $res  = $this->import->importInitialStock(
                $rows,
                $this->operator(),
                (string) Request::post('file_name', '') ?: null
            );
            return $this->jsonOk($res, '导入完成');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }

    /** GET /trade/toolbox/importLogs —— 导入历史日志 */
    public function importLogs(): Response
    {
        $data = $this->import->logs(
            (string) $this->arg('import_type', ''),
            (int) $this->arg('limit', 50)
        );
        return $this->jsonOk($this->withStatusLabels($data, 'import_log'));
    }

    /** GET /trade/toolbox/importTemplate —— 导入模板 CSV 下载（期初库存） */
    public function importTemplate(): Response
    {
        $type = (string) $this->arg('import_type', ImportService::TYPE_INITIAL_STOCK);
        if (ImportService::TYPE_INITIAL_STOCK !== $type) {
            return $this->csvResponse([], [], 'import_template');
        }
        $header = [
            'product_no'     => '商品编码',
            'warehouse_name' => '仓库',
            'quantity'       => '数量',
            'cost_price'     => '成本单价（可空）',
        ];
        $rows = [
            ['product_no' => 'example-001', 'warehouse_name' => '主仓', 'quantity' => '100', 'cost_price' => '9.50'],
        ];
        return $this->csvResponse($rows, $header, '期初库存导入模板');
    }

    // ---------------- 草稿单 ----------------

    /** GET /trade/toolbox/draft —— 草稿单页 */
    public function draft(): Response
    {
        return $this->render('toolbox/draft');
    }

    /** GET /trade/toolbox/draftRows —— 到期提醒草稿 + 已超保留期草稿 */
    public function draftRows(): Response
    {
        return $this->jsonOk([
            'remind'  => $this->draft->remindDrafts(),
            'expired' => $this->draft->expiredDrafts(),
        ]);
    }

    /** POST /trade/toolbox/draftDelete —— 删除草稿（级联清理明细） */
    public function draftDelete(): Response
    {
        $docType = (string) Request::post('doc_type', '');
        $id      = (int) Request::post('id', 0);
        if ($this->draft->deleteDraft($docType, $id)) {
            return $this->jsonOk(null, '草稿已删除');
        }
        return $this->jsonFail('删除失败：草稿不存在或已非草稿状态');
    }

    /** POST /trade/toolbox/draftEdit —— 编辑草稿字段（备注；采购单含预计到货） */
    public function draftEdit(): Response
    {
        $docType = (string) Request::post('doc_type', '');
        $id      = (int) Request::post('id', 0);
        $fields  = [];
        $remark  = Request::post('remark', null);
        if (null !== $remark) {
            $fields['remark'] = (string) $remark;
        }
        if ('purchase_order' === $docType && null !== Request::post('expected_arrival_at', null)) {
            $fields['expected_arrival_at'] = (string) Request::post('expected_arrival_at');
        }
        if ([] === $fields) {
            return $this->jsonFail('没有可编辑的字段');
        }
        return $this->draft->editDraft($docType, $id, $fields)
            ? $this->jsonOk(null, '草稿已更新')
            : $this->jsonFail('更新失败：草稿不存在或已非草稿状态');
    }

    /** POST /trade/toolbox/draftPromote —— 草稿转正式单（提交；采购/销售订单走对应 service） */
    public function draftPromote(): Response
    {
        $docType = (string) Request::post('doc_type', '');
        $id      = (int) Request::post('id', 0);
        if (!$this->draft->find($docType, $id)) {
            return $this->jsonFail('草稿不存在或已非草稿状态');
        }
        try {
            if ('sale_order' === $docType) {
                $this->sale->submit($id);
            } elseif ('purchase_order' === $docType) {
                $this->purchase->submit($id);
            } else {
                return $this->jsonFail('暂不支持将该类型草稿转正式');
            }
            return $this->jsonOk(null, '草案已提交为正式单据');
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage());
        }
    }
}