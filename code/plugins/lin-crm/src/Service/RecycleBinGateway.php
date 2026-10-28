<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Service;

/**
 * 平台回收站服务网关（FR-TOOL-02 接入点）。
 *
 * 本包不再自建回收站存储（lg_lin-crm_recycle_bin 已废弃并删除），
 * 软删快照统一托管宿主第 16 个公共服务 recyclebin（lg_mgt_recycle_bin）：
 *  - 命名空间隔离由服务端按调用文件路径自动判定为 lin-crm（callerFromTrace）；
 *  - put() 的 ttlDays 映射包配置 recycle.retention_days（GR-2，默认 30 天）；
 *  - restore() 仅返回数据副本，写回原表由本包完成（恢复查重 BND-07）。
 */
final class RecycleBinGateway
{
    /**
     * 惰性获取平台回收站服务实例（RecordingServiceProxy 自动计量）。
     * 注：代理对象不满足 RecycleBin 契约的 instanceof（平台约定只按方法消费），故不做类型断言。
     */
    public static function svc(): object
    {
        $svc = \linhub\host\communication\HostServiceApi::getService(app(), 'recyclebin');
        if (!is_callable([$svc, 'put'])) {
            throw new \RuntimeException('平台回收站服务不可用');
        }
        return $svc;
    }

    /** 入站托管：软删快照进平台回收站，返回条目 token。 */
    public static function put(string $sourceTable, int $sourceId, array $row, int $operatorId): string
    {
        $ttl = (int) (config('crm.recycle.retention_days') ?? 30);
        return self::svc()->put(null, $sourceTable, (string) $sourceId, $row, [
            'itemKey' => (string) ($row['code'] ?? $sourceId),
            'title'   => (string) ($row['name'] ?? ('#' . $sourceId)),
            'meta'    => ['operator_id' => $operatorId, 'src_table' => $sourceTable, 'src_id' => $sourceId],
            'ttlDays' => $ttl > 0 ? $ttl : null,
        ]);
    }
}
