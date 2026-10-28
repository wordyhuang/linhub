<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\Customer;
use Lg\Pack\Trade\model\SaleOrder;
use Lg\Pack\Trade\model\SaleOrderItem;
use Lg\Pack\Trade\model\SaleReturn;
use Lg\Pack\Trade\model\SaleReturnItem;
use Lg\Pack\Trade\Support\TradeEvents;
use think\facade\App;
use think\facade\Db;

/**
 * 销售退货服务（PRD 3.3.3 / 状态机 4.3）。
 *
 * 独立退货单（SO-RT）。状态机受配置 trade.return.require_check 分支：
 *  - require_check=off：draft(草稿) -> submitted(已提交) -> done(已完成)，回补库存发生在完成过渡；
 *  - require_check=on ：draft(草稿) -> submitted(已提交) -> checked(已验退) -> done(已完成)，
 *                       验退通过后回补库存，验收不通过(原路退回客户)不回补库存 -> voided(已作废)。
 *
 * 两类草稿/已提交在"未回补库存"前均可作废。库存回补统一在 complete()（完成过渡）执行，
 * 与采购退货的扣减时序对称；完成时发布 lg.trade.sale_return.confirmed，携带 customer_id 供信用释放订阅。
 *
 * 可退上限精确到销售单明细：可退数量 = 该行已出库 outbound_qty - 已退 returned_qty；
 * 退款按原出库行单价×数量（红字），完成后由 refund() 标记冲应收/退款。
 */
class SaleReturnService
{
    public function __construct(
        private InventoryService $inventory,
        private DocNumberService $docNo
    ) {
    }

    /**
     * 创建销售退货草稿（不预占、不回补库存、不累加 returned_qty）。
     *
     * @param array $data items: [[sale_item_id, quantity, price?, product_id?]]
     */
    public function create(int $saleOrderId, array $data, int $userId): SaleReturn
    {
        $order = SaleOrder::find($saleOrderId);
        if (!$order) {
            throw new \RuntimeException('销售订单不存在');
        }
        if (empty($data['items'])) {
            throw new \RuntimeException('销售退货至少需要一行明细');
        }

        $requireCheck = 'on' === (string) $this->config('return.require_check', 'off');
        $lines = $this->buildLines($saleOrderId, $data['items']);

        Db::startTrans();
        try {
            $return = SaleReturn::create([
                'return_no'     => $this->docNo->next('SO-RT', 'lg_lin-trade_sale_return', 'return_no'),
                'sale_order_id' => $saleOrderId,
                'customer_id'   => $order->customer_id,
                'status'        => 'draft',
                'require_check' => $requireCheck ? 1 : 0,
                'refund_flag'   => 0,
                'total_amount'  => array_sum(array_column($lines, 'amount')),
                'remark'        => $data['remark'] ?? null,
                'created_by'    => $userId,
            ]);
            foreach ($lines as $line) {
                SaleReturnItem::create([
                    'return_id'        => $return->id,
                    'sale_order_item_id' => $line['sale_order_item_id'],
                    'product_id'       => $line['product_id'],
                    'quantity'         => $line['quantity'],
                    'price'            => $line['price'],
                    'amount'           => $line['amount'],
                ]);
            }
            Db::commit();
            return $return;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 提交：草稿 -> 已提交 */
    public function submit(int $id): bool
    {
        $return = SaleReturn::find($id);
        $this->assertExists($return);
        if ('draft' !== $return->status) {
            throw new \RuntimeException('仅草稿状态可提交，当前：' . $return->status);
        }
        return (bool) SaleReturn::where('id', $id)->update(['status' => 'submitted']);
    }

    /**
     * 验退（仅 require_check=on 分支，已提交状态）。
     *
     * @param bool $pass true=验退通过 -> checked；false=验收不通过，原路退回客户 -> voided（不回补库存）
     */
    public function check(int $id, bool $pass, string $remark = null): bool
    {
        $return = SaleReturn::find($id);
        $this->assertExists($return);
        if (!(int) $return->require_check) {
            throw new \RuntimeException('该退货单不要求验退（trade.return.require_check=off）');
        }
        if ('submitted' !== $return->status) {
            throw new \RuntimeException('仅已提交状态可验退，当前：' . $return->status);
        }
        if ($pass) {
            return (bool) SaleReturn::where('id', $id)->update([
                'status'       => 'checked',
                'check_result' => 'passed',
            ]);
        }
        return (bool) SaleReturn::where('id', $id)->update([
            'status'       => 'voided',
            'check_result' => 'failed',
            'void_reason'  => $remark ?: '验收不通过，原路退回客户',
        ]);
    }

    /**
     * 完成销售退货：回补库存并累加原销售单明细的 returned_qty，发布销售退货确认事件。
     * 完成时为信用占用释放留口：通过事件下发 customer_id/total_amount 由信用订阅方消费。
     */
    public function complete(int $id, string $operator): bool
    {
        $return = SaleReturn::find($id);
        $this->assertExists($return);
        $requireCheck = (int) $return->require_check;
        $allowed = $requireCheck ? 'checked' : 'submitted';
        if ($return->status !== $allowed) {
            throw new \RuntimeException("当前状态不可完成退货（需 {$allowed}）：{$return->status}");
        }
        if (!$return->sale_order_id) {
            throw new \RuntimeException('退货单缺少来源销售订单');
        }

        $order = SaleOrder::find($return->sale_order_id);
        $items = SaleReturnItem::where('return_id', $id)->select()->toArray();

        Db::startTrans();
        try {
            $invRows = [];
            foreach ($items as $rit) {
                $soItem = SaleOrderItem::where('id', $rit['sale_order_item_id'])
                    ->where('order_id', $return->sale_order_id)
                    ->find();
                if (!$soItem) {
                    throw new \RuntimeException("退货明细缺少对应销售行[{$rit['id']}]");
                }
                $invRows[] = $this->applyReturnLine($soItem, (float) $rit['quantity'], (int) $order->warehouse_id, $id);
            }
            $this->inventory->applyMany($invRows, $operator);
            SaleReturn::where('id', $id)->update([
                'status'     => 'done',
                'checked_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        TradeEvents::publish(TradeEvents::SALE_RETURN_CONFIRMED, [
            'return_id'      => $id,
            'return_no'      => $return->return_no,
            'sale_order_id'  => $return->sale_order_id,
            'customer_id'    => $return->customer_id,
            'total_amount'   => $return->total_amount,
            'need_credit_release' => true,
        ]);
        return true;
    }

    /**
     * 标记退款/冲应收（PRD 3.3.3：退款按原出库行单价×数量，红字）。
     * 实际红字冲销由财务对账订阅处理，此处仅做单据标记。
     */
    public function refund(int $id, string $operator): bool
    {
        $return = SaleReturn::find($id);
        $this->assertExists($return);
        if ('done' !== $return->status) {
            throw new \RuntimeException('仅已完成销售退货可标记退款');
        }
        return (bool) SaleReturn::where('id', $id)->update([
            'refund_flag' => 1,
            'refund_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** 作废：仅草稿/已提交（未回补库存）可作废；已验退(checked)未回补也可作废 */
    public function void(int $id, string $reason): bool
    {
        $return = SaleReturn::find($id);
        $this->assertExists($return);
        if (!in_array($return->status, ['draft', 'submitted', 'checked'], true)) {
            throw new \RuntimeException('仅草稿/已提交/已验退状态可作废，当前：' . $return->status);
        }
        return (bool) SaleReturn::where('id', $id)->update([
            'status'      => 'voided',
            'void_reason' => $reason,
        ]);
    }

    public function detail(int $id): ?array
    {
        $return = SaleReturn::find($id);
        if (!$return) {
            return null;
        }
        $data = $return->toArray();
        $data['items'] = SaleReturnItem::where('return_id', $id)->select()->toArray();
        if ($return->customer_id) {
            $data['customer_name'] = Customer::where('id', $return->customer_id)->value('name');
        }
        return $data;
    }

    public function page(array $cond, int $page = 1, int $pageSize = 20): array
    {
        $query = SaleReturn::order('id', 'desc');
        if (!empty($cond['status'])) {
            $query->where('status', $cond['status']);
        }
        if (!empty($cond['return_no'])) {
            $query->whereLike('return_no', "%{$cond['return_no']}%");
        }
        return [
            'total' => $query->count(),
            'list'  => $query->page($page, $pageSize)->select()->toArray(),
        ];
    }

    // ---------------- 内部 ----------------

    /** 组装并预校验退货行：quantity 与可退上限（已出-已退）对齐，price 缺省取原出库行单价 */
    private function buildLines(int $saleOrderId, array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            if (empty($row['sale_item_id'])) {
                throw new \RuntimeException('退货明细缺少销售行标识');
            }
            $soItem = SaleOrderItem::where('id', $row['sale_item_id'])
                ->where('order_id', $saleOrderId)
                ->find();
            if (!$soItem) {
                throw new \RuntimeException('销售行不存在：' . $row['sale_item_id']);
            }
            $qty   = round((float) $row['quantity'], 4);
            $avail = round((float) $soItem->outbound_qty - (float) $soItem->returned_qty, 4);
            if ($qty <= 0) {
                throw new \RuntimeException('退货数量必须大于 0');
            }
            if ($qty > $avail) {
                throw new \RuntimeException("销售行[{$soItem->id}]可退上限[{$avail}]（已出" .
                    (float) $soItem->outbound_qty . '-已退' . (float) $soItem->returned_qty . '），超量拒绝');
            }
            $price = isset($row['price']) ? round((float) $row['price'], 4) : (float) $soItem->price;
            $lines[] = [
                'sale_order_item_id' => (int) $soItem->id,
                'product_id'         => (int) $soItem->product_id,
                'quantity'           => $qty,
                'price'              => $price,
                'amount'             => round($qty * $price, 2),
            ];
        }
        return $lines;
    }

    /** 单行消费可退上限并生成库存回补行；同时累加原销售行 returned_qty */
    private function applyReturnLine($soItem, float $qty, int $warehouseId, int $refId): array
    {
        $avail = round((float) $soItem->outbound_qty - (float) $soItem->returned_qty, 4);
        if ($qty > $avail) {
            throw new \RuntimeException("销售行[{$soItem->id}]可退上限[{$avail}]，当前退货[{$qty}]超量");
        }
        $soItem->returned_qty = round((float) $soItem->returned_qty + $qty, 4);
        $soItem->save();

        return [
            'change_type'  => InventoryService::TYPE_SALE_RETURN,
            'product_id'   => (int) $soItem->product_id,
            'warehouse_id' => $warehouseId,
            'delta'        => $qty,
            'ref_id'       => $refId,
            'remark'       => '销售退货',
        ];
    }

    private function config(string $key, $default = null)
    {
        try {
            return App::make('config')->get('pack.lin-trade.' . $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function assertExists($return): void
    {
        if (!$return) {
            throw new \RuntimeException('销售退货单不存在');
        }
    }
}