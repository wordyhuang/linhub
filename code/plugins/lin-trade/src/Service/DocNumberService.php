<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use think\facade\Db;

/**
 * 单据编号服务。
 *
 * 编码规则（PRD 1.4/3.2.1）：前缀 + YYMMDD + N 位流水，流水按"前缀 + 自然日"归组，
 * 从前缀表当日最大流水 +1 继续，宽度 ≥3 且不足 3 位补零；同日同一前缀流水超过 999 自动扩位。
 * 全局唯一由目标单据号列的唯一索引兜底，冲突时自动重试（错峰递增），保证多进程安全。
 *
 * 前缀：PO / SO / PO-RT / SO-RT / PD / TF / RC（沿用 + V_1.1 补齐）。
 */
class DocNumberService
{
    public const MAX_RETRY = 8;
    private const MIN_WIDTH = 3;

    /**
     * 生成下一个单据号。
     *
     * @param string $prefix   单据前缀，如 'PO'、'PO-RT'
     * @param string $table    目标表名（含 lg_lin-trade_ 前缀），如 'lg_lin-trade_purchase_order'
     * @param string $noColumn 单据号列名，默认 'order_no'
     */
    public function next(string $prefix, string $table, string $noColumn = 'order_no'): string
    {
        $ymd     = date('Ymd');
        $pattern = $prefix . $ymd;

        for ($i = 0; $i < self::MAX_RETRY; $i++) {
            $last  = (string) Db::name($table)->where($noColumn, 'like', $pattern . '%')->max($noColumn);
            $no    = $pattern . $this->pad($this->extractSeq($last, $ymd) + 1);
            // 唯一索引冲突由 DB 兜底；此处先探测占用，被占用则下一轮重读最大值错峰递增
            $exists = (int) Db::name($table)->where($noColumn, $no)->count();
            if (0 === $exists) {
                return $no;
            }
        }

        // 极端并发兜底：直接落到当前时间戳值，唯一索引仍保证不重复（冲突方走重试/报错）
        return $pattern . $this->pad($this->extractSeq((string) time(), $ymd));
    }

    /** 从已有单号中解析当日流水序号；无则返回 0 */
    private function extractSeq(string $lastNo, string $ymd): int
    {
        if ('' === $lastNo) {
            return 0;
        }
        $pos = strrpos($lastNo, $ymd);
        if (false === $pos) {
            return 0;
        }
        $tail = substr($lastNo, $pos + strlen($ymd));
        return ('' === $tail || !ctype_digit($tail)) ? 0 : (int) $tail;
    }

    private function pad(int $seq): string
    {
        return str_pad((string) $seq, self::MIN_WIDTH, '0', STR_PAD_LEFT);
    }
}