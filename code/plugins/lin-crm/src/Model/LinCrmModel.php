<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Model;

use think\facade\Db;

/**
 * 林客模型基类：软删过滤 / 留痕 / 编码生成委托
 * 表名规则：lg_lin-crm_ + 蛇形短名（子类 $name 指定）。
 */
abstract class LinCrmModel
{
    /** @var string 不含前缀的表短名，如 customer */
    protected string $name = '';
    /** @var string 编码前缀：KH/XS/SJ/HT，空串表示无编码 */
    protected string $codePrefix = '';

    public function table(): string
    {
        return 'lg_lin-crm_' . $this->name;
    }

    /** 查询入口（自动过滤软删，RL-3） */
    public function q()
    {
        return Db::name('lg_lin-crm_' . $this->name)->whereNull('deleted_at');
    }

    /** 编码生成：前缀 + yyyyMMdd + 4 位流水（GR-1，事务内调用防并发重号） */
    public function nextCode(): string
    {
        $day  = date('Ymd');
        $seq  = Db::name('lg_lin-crm_' . $this->name)
            ->whereLike('code', $this->codePrefix . $day . '%')
            ->useSoftDelete('deleted_at', null)
            ->count();
        return sprintf('%s%s%04d', $this->codePrefix, $day, (int)$seq + 1);
    }

    /** 软删：写 deleted_at 并将快照托管平台回收站服务（RL-3；FR-TOOL-02 接入 recyclebin） */
    public function softDelete(int $id, int $operatorId): void
    {
        $row = $this->q()->where('id', $id)->find();
        if (!$row) {
            throw new \RuntimeException('record not found');
        }
        Db::startTrans();
        try {
            $this->q()->where('id', $id)->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // 快照托管宿主回收站服务（lg_mgt_recycle_bin，namespace=lin-crm）；
            // 托管失败视为软删失败回滚，避免出现无快照的"不可恢复"删除。
            \Lg\Pack\LinCrm\Service\RecycleBinGateway::put($this->table(), $id, $row, $operatorId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
}
