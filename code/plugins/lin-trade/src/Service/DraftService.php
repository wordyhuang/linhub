<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use think\facade\App;
use think\facade\Db;

/**
 * 草稿单保留策略服务（PRD 3.6 草稿单，trade.draft.keep_days 默认 30）。
 *
 * 草稿单本身不预占库存、不参与信用占用、不发放正式单据号；可继续编辑、软删、转正式。
 * 本服务只负责"保留期"策略：
 *  - 到期前 3 天提醒创建人（remindDrafts）；
 *  - 到期仅提醒、不自动删除（expiredDrafts 仅用于查询展示）。
 * 采购/销售/采购退货/销售退货四种单据的草稿统一暴露给定时任务（每日 08:00）消费。
 */
class DraftService
{
    /**
     * 采集各单据的草稿到期提醒列表：创建时间早于 (keep_days - 3) 天、仍为草稿态（未被作废）的单。
     * 供每日定时任务向创建人推送提醒；保留仍未超 keep_days，不触发删除。
     */
    public function remindDrafts(): array
    {
        $keepDays = (int) $this->config('draft.keep_days', 30);
        $remindAt = date('Y-m-d H:i:s', strtotime('-' . max(0, $keepDays - 3) . ' days'));
        $result   = [];
        foreach ($this->draftSources() as $src) {
            $rows = Db::name($src['table'])
                ->where('status', 'draft')
                ->where('created_at', '<', $remindAt)
                ->field('id, ' . $src['noCol'] . ' as doc_no, created_by, created_at')
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $r['doc_type'] = $src['doc_type'];
                $r['doc_no']   = $r['doc_no'] ?? '';
                $r['remind']   = true;
                $result[]      = $r;
            }
        }
        return $result;
    }

    /**
     * 已到保留期（超过 keep_days 天）的草稿单：按 PRD 仅查询、不自动删除。
     * 便于管理员人工处理（trade:draft:manage）。
     */
    public function expiredDrafts(): array
    {
        $keepDays = (int) $this->config('draft.keep_days', 30);
        $expiredA = date('Y-m-d H:i:s', strtotime('-' . max(1, $keepDays) . ' days'));
        $result   = [];
        foreach ($this->draftSources() as $src) {
            $rows = Db::name($src['table'])
                ->where('status', 'draft')
                ->where('created_at', '<', $expiredA)
                ->field('id, ' . $src['noCol'] . ' as doc_no, created_by, created_at')
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $r['doc_type'] = $src['doc_type'];
                $result[]      = $r;
            }
        }
        return $result;
    }

    /** 草稿来源表集合（含单据号列名 / 明细表 / 明细外键） */
    private function draftSources(): array
    {
        return [
            [
                'table'     => 'lg_lin-trade_purchase_order',
                'noCol'     => 'order_no',
                'doc_type'  => 'purchase_order',
                'itemTable' => 'lg_lin-trade_purchase_order_item',
                'itemFk'    => 'order_id',
            ],
            [
                'table'     => 'lg_lin-trade_sale_order',
                'noCol'     => 'order_no',
                'doc_type'  => 'sale_order',
                'itemTable' => 'lg_lin-trade_sale_order_item',
                'itemFk'    => 'order_id',
            ],
            [
                'table'     => 'lg_lin-trade_purchase_return',
                'noCol'     => 'return_no',
                'doc_type'  => 'purchase_return',
                'itemTable' => 'lg_lin-trade_purchase_return_item',
                'itemFk'    => 'return_id',
            ],
            [
                'table'     => 'lg_lin-trade_sale_return',
                'noCol'     => 'return_no',
                'doc_type'  => 'sale_return',
                'itemTable' => 'lg_lin-trade_sale_return_item',
                'itemFk'    => 'return_id',
            ],
        ];
    }

    /** 按单据类型解析草稿来源映射；未知类型返回 null。 */
    private function tableOf(string $docType): ?array
    {
        foreach ($this->draftSources() as $src) {
            if ($src['doc_type'] === $docType) {
                return $src;
            }
        }
        return null;
    }

    /**
     * 解析单张草稿主单（供编辑回填 / 转正式校验）。
     * 仅草稿态有效；未知类型、非草稿或不存在返回 null。
     */
    public function find(string $docType, int $id): ?array
    {
        $src = $this->tableOf($docType);
        if (null === $src) {
            return null;
        }
        $row = Db::name($src['table'])->where('id', $id)->where('status', 'draft')->find();
        return $row ?: null;
    }

    /**
     * 编辑草稿主单字段（白名单，仅草稿态）。返回是否发生更新。
     * 允许改写 remark；采购订单额外允许 expected_arrival_at。
     */
    public function editDraft(string $docType, int $id, array $fields): bool
    {
        $src = $this->tableOf($docType);
        if (null === $src) {
            return false;
        }
        $allow = ['remark'];
        if ('purchase_order' === $docType) {
            $allow[] = 'expected_arrival_at';
        }
        $data = array_intersect_key($fields, array_flip($allow));
        if ([] === $data) {
            return false;
        }
        try {
            return 1 === Db::name($src['table'])
                ->where('id', $id)
                ->where('status', 'draft')
                ->update($data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 删除草稿（事务级联清理明细；销售单一并清理价格审批孤儿行）。仅草稿态可删。
     */
    public function deleteDraft(string $docType, int $id): bool
    {
        $src = $this->tableOf($docType);
        if (null === $src) {
            return false;
        }
        try {
            Db::startTrans();
            $exists = Db::name($src['table'])->where('id', $id)->where('status', 'draft')->find();
            if (!$exists) {
                Db::commit();
                return false;
            }
            Db::name($src['itemTable'])->where($src['itemFk'], $id)->delete();
            if ('sale_order' === $docType) {
                Db::name('lg_lin-trade_price_approval')->where('sale_order_id', $id)->delete();
            }
            Db::name($src['table'])->where('id', $id)->delete();
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
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