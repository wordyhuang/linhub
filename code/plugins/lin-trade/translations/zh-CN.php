<?php

declare(strict_types=1);

/**
 * 林销（lin-trade）状态翻译种子 · zh-CN。
 *
 * 由宿主翻译服务种子机制托管（services/translation/TranslationSeeder.php）：
 *  - 安装：幂等 upsert；升级：全量覆盖；卸载：清除。
 *  - namespace 固定为包短名 lin-trade，key 规范：<context>.status.<value>（如 purchase_order.status.draft）、
 *    通用字段 <context>.<field>.<value>（如 sale_return.check_result.passed）、启停用 common.status.1/0。
 *  - 文案与各前端页既有中文硬编码保持一致（如调拨 submitted=在途、盘点 adjusted=已调整）。
 */
return [
    // —— 采购订单（PurchaseService）——
    'purchase_order.status.draft'     => '草稿',
    'purchase_order.status.submitted' => '已提交',
    'purchase_order.status.partial'   => '部分入库',
    'purchase_order.status.done'      => '已完成',
    'purchase_order.status.closed'    => '已关闭',
    'purchase_order.status.voided'    => '已作废',

    // —— 销售订单（SaleService）——
    'sale_order.status.draft'         => '草稿',
    'sale_order.status.pending_audit' => '待审核',
    'sale_order.status.submitted'     => '已提交',
    'sale_order.status.partial'       => '部分出库',
    'sale_order.status.done'          => '已完成',
    'sale_order.status.closed'        => '已关闭',
    'sale_order.status.voided'        => '已作废',

    // —— 采购退货单（PurchaseReturnService）——
    'purchase_return.status.draft'    => '草稿',
    'purchase_return.status.submitted' => '已提交',
    'purchase_return.status.done'     => '已完成',
    'purchase_return.status.voided'   => '已作废',

    // —— 销售退货单（SaleReturnService）——
    'sale_return.status.draft'        => '草稿',
    'sale_return.status.submitted'    => '已提交',
    'sale_return.status.checked'      => '已验退',
    'sale_return.status.done'         => '已完成',
    'sale_return.status.voided'       => '已作废',
    'sale_return.check_result.passed' => '验退通过',
    'sale_return.check_result.failed' => '验退退回',

    // —— 库存盘点单（StocktakeService）——
    'stocktake.status.taking'         => '盘点中',
    'stocktake.status.confirmed'      => '已确认',
    'stocktake.status.adjusted'       => '已调整',

    // —— 库存调拨单（TransferService）——
    'transfer.status.submitted'       => '在途',
    'transfer.status.done'            => '已完成',

    // —— 对账单（ReconciliationService）——
    'reconciliation.status.generated' => '已生成',
    'reconciliation.status.confirmed' => '已确认',
    'reconciliation.status.sent'      => '已发送',
    'reconciliation.status.voided'    => '已作废',

    // —— 批量导入日志（ImportService）——
    'import_log.status.running'       => '进行中',
    'import_log.status.success'       => '全部成功',
    'import_log.status.partial'       => '部分成功',
    'import_log.status.failed'        => '导入失败',

    // —— 价格审批（PriceRuleService）——
    'price_approval.status.pending'   => '待审批',
    'price_approval.status.approved'  => '已通过',
    'price_approval.status.rejected'  => '已驳回',

    // —— 启停用布尔列（product/category/supplier/unit 等 status 1/0）——
    'common.status.1'                 => '启用',
    'common.status.0'                 => '停用',
];
