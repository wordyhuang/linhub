<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use Lg\Pack\Trade\model\ImportLog;
use Lg\Pack\Trade\model\Inventory;
use Lg\Pack\Trade\model\InventoryLog;
use Lg\Pack\Trade\model\Product;
use Lg\Pack\Trade\model\Warehouse;
use think\facade\App;
use think\facade\Db;

/**
 * 批量导入服务（PRD 3.6 通用支撑，trade:import:execute）。
 *
 * 期初库存导入（P0）：
 *  - 模板：商品编码 / 仓库 / 数量 / 成本单价；
 *  - 红线：仅允许导入无任何库存流水（非 initial）且当前无库存的商品，否则拒绝并提示走盘点校正；
 *  - 导入成功生成 initial 类型库存流水留痕，并按成本单价初始化加权成本基数；
 *  - 每次导入生成导入日志（批次号/类型/总行数/成功数/失败明细/状态机 running/success/partial/failed）。
 *
 * 通用规则：单次行数上限 trade.import.max_rows（默认 1000）；逐行校验报错定位到行号与列名；
 * 遇错策略 trade.import.error_policy（stop 整批停止回滚 / skip 跳过错误行汇总报告）。
 */
class ImportService
{
    /** 导入类型 */
    public const TYPE_INITIAL_STOCK = 'initial_stock';

    public const STATUS_RUNNING  = 'running';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_PARTIAL  = 'partial';
    public const STATUS_FAILED   = 'failed';

    private InventoryService $inventory;

    public function __construct()
    {
        // 由宿主管线注入库存服务，避免在行级循环里重复解析
        $this->inventory = App::make(InventoryService::class);
    }

    /**
     * 执行期初库存导入（PRD 3.6）。
     *
     * @param array  $rows    每项 product_no(商品编码)/warehouse_name(仓库名)/quantity(数量)/cost_price(成本单价)
     * @param string $operator 操作人
     * @param string|null $fileName 导入文件名（记录日志）
     * @return array{batch_no:string,status:string,total_rows:int,success_rows:int,skipped:array}[]
     */
    public function importInitialStock(array $rows, string $operator, ?string $fileName = null): array
    {
        $maxRows = max(1, (int) $this->config('import.max_rows', 1000));
        $policy  = (string) $this->config('import.error_policy', 'stop');

        $batchNo = $this->batchNo();
        $total   = count($rows);
        $log     = $this->newLog($batchNo, self::TYPE_INITIAL_STOCK, $total, $fileName, $operator);

        try {
            if ($total > $maxRows) {
                throw new \RuntimeException("单次导入超上限：共{$total}行，上限{$maxRows}行");
            }
            if (0 === $total) {
                throw new \RuntimeException('导入文件没有有效数据行');
            }

            // 逐行校验：编码/仓库存在、数值合法；校验错误定位到行号与列名
            $valid    = [];
            $skipped  = [];
            foreach ($rows as $idx => $row) {
                $line = $idx + 1;
                $err  = $this->validateRow($row, $line);
                if (null !== $err) {
                    $skipped[] = $err;
                    continue;
                }
                $valid[] = $row;
            }

            if (!empty($skipped) && 'stop' === $policy) {
                throw new \RuntimeException($this->joinSkips($skipped));
            }

            // 红线预检：无业务流水 & 无既有库存的商品才允许导期初（与 InventoryService 一致）
            $valid = $this->filterInitialEligible($valid, $skipped);

            Db::startTrans();
            try {
                $imported = 0;
                foreach ($valid as $row) {
                    $qty = (float) $row['quantity'];
                    if ($qty > 0) {
                        $this->inventory->apply(
                            InventoryService::TYPE_INITIAL, $row['product_id'], $row['warehouse_id'], $qty,
                            null, $operator, '期初库存导入',
                            isset($row['cost_price']) ? (float) $row['cost_price'] : null
                        );
                        $imported++;
                    }
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            $status = (0 === $imported && !empty($skipped))
                ? self::STATUS_FAILED
                : (!empty($skipped) ? self::STATUS_PARTIAL : self::STATUS_SUCCESS);
            $this->finishLog($log, $imported, $status, $skipped, null);

            return [
                'batch_no'     => $batchNo,
                'status'       => $status,
                'total_rows'   => $total,
                'success_rows' => $imported,
                'skipped'      => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->finishLog($log, 0, self::STATUS_FAILED, $skipped ?? [], $e->getMessage());
            return [
                'batch_no'     => $batchNo,
                'status'       => self::STATUS_FAILED,
                'total_rows'   => $total,
                'success_rows' => 0,
                'skipped'      => $skipped ?? [],
                'error'        => $e->getMessage(),
            ];
        }
    }

    /** 查询导入日志（历史批次 + 成功行数） */
    public function logs(string $importType = '', int $limit = 50): array
    {
        $q = ImportLog::order('id', 'desc')->limit($limit);
        if ('' !== $importType) {
            $q->where('import_type', $importType);
        }
        return $q->select()->toArray();
    }

    // ---------------- 内部 ----------------

    /** 单行校验，返回定位到行号与列名的错误描述；合法返回 null */
    private function validateRow(array $row, int $line): ?string
    {
        if (empty($row['product_no'])) {
            return "第{$line}行[商品编码]缺失";
        }
        $product = Product::where('product_no', $row['product_no'])->find();
        if (!$product) {
            return "第{$line}行[商品编码]{$row['product_no']}不存在";
        }
        if (empty($row['warehouse_name'])) {
            return "第{$line}行[仓库]缺失";
        }
        $warehouse = Warehouse::where('name', $row['warehouse_name'])->find();
        if (!$warehouse) {
            return "第{$line}行[仓库]'{$row['warehouse_name']}'不存在";
        }
        $qty = isset($row['quantity']) ? (float) $row['quantity'] : 0;
        if ($qty <= 0) {
            return "第{$line}行[数量]非法，须大于 0";
        }
        if (isset($row['cost_price']) && (float) $row['cost_price'] < 0) {
            return "第{$line}行[成本单价]非法，不能为负数";
        }
        // 回填解析后的主键
        $row['product_id']   = (int) $product->id;
        $row['warehouse_id'] = (int) $warehouse->id;
        $row['cost_price']   = isset($row['cost_price']) ? (float) $row['cost_price'] : (float) $product->reference_cost_price;
        // 保留扣除 code 字段后的原始数据供期初导入使用
        foreach (['product_no' => 'product_id', 'warehouse_name' => 'warehouse_id'] as $k => $v) {
            unset($row[$k]);
        }
        return null;
    }

    /** 红线预检与回填：剔除已有业务流水或已被期初占用的商品行（防双轨改数） */
    private function filterInitialEligible(array $rows, array &$skipped): array
    {
        $eligible = [];
        foreach ($rows as $row) {
            $pid  = (int) $row['product_id'];
            $wid  = (int) $row['warehouse_id'];
            $hasLog = (int) InventoryLog::where('product_id', $pid)
                ->where('change_type', '<>', InventoryService::TYPE_INITIAL)
                ->count() > 0;
            if ($hasLog) {
                $skipped[] = "商品[{$pid}]已有业务库存流水，不能导期初，请走盘点校正";
                continue;
            }
            $inv = Inventory::where('product_id', $pid)->where('warehouse_id', $wid)->find();
            if ($inv && (float) $inv->quantity > 0) {
                $skipped[] = "商品[{$pid}]仓库[{$wid}]已有库存，禁止重复导入期初";
                continue;
            }
            $eligible[] = $row;
        }
        return $eligible;
    }

    private function newLog(string $batchNo, string $type, int $total, ?string $fileName, string $operator): ImportLog
    {
        return ImportLog::create([
            'batch_no'     => $batchNo,
            'import_type'  => $type,
            'file_name'    => $fileName,
            'total_rows'   => $total,
            'success_rows' => 0,
            'status'       => self::STATUS_RUNNING,
            'operator'     => $operator,
        ]);
    }

    private function finishLog(ImportLog $log, int $success, string $status, array $skipped = [], ?string $error = null): void
    {
        $remark = $error;
        if ('' === $remark && !empty($skipped)) {
            $remark = $this->joinSkips($skipped, true);
        }
        ImportLog::where('id', $log->id)->update([
            'success_rows' => $success,
            'status'       => $status,
            'error_msg'    => $remark,
        ]);
    }

    private function joinSkips(array $skipped, bool $compact = false): string
    {
        return implode('；', $skipped);
    }

    private function batchNo(): string
    {
        $seq = (string) Db::name('lg_lin-trade_import_log')->max('id');
        return 'IMP' . date('YmdHis') . str_pad(substr((string) ((int) $seq + 1), -3), 3, '0', STR_PAD_LEFT);
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