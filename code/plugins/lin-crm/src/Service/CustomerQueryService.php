<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Service;

use think\facade\Db;

/**
 * 对外服务：crm.customer.query（manifest services.provides 注册）
 * 按客户 id / 名称 / 手机号查询客户主档与归属，供矩阵兄弟产品（如林销散单映射）调用。
 * 注意：调用方鉴权由宿主服务注册表负责；本服务不返回软删数据（RL-3）。
 */
class CustomerQueryService
{
    /**
     * @param array{ids?:int[], name?:string, phone?:string} $cond
     * @return array 客户主档列表（id/code/name/phone/owner_id/pool_status/level/industry）
     */
    public function query(array $cond): array
    {
        $q = Db::name('lg_lin-crm_customer')->whereNull('deleted_at');

        if (!empty($cond['ids'])) {
            $q->whereIn('id', array_map('intval', $cond['ids']));
        }
        if (!empty($cond['name'])) {
            $q->where('name', '=', trim($cond['name'])); // 精确匹配（GR-4 同源口径）
        }
        if (!empty($cond['phone'])) {
            $q->where('phone', '=', trim($cond['phone']));
        }
        if (empty($cond['ids']) && empty($cond['name']) && empty($cond['phone'])) {
            throw new \InvalidArgumentException('query condition required'); // 防全表拉取
        }

        return $q->field('id,code,name,phone,owner_id,pool_status,level,industry')
            ->limit(200)
            ->select()
            ->toArray();
    }

    /** 客户归属与保护期（公海/私海判定，供兄弟产品判断可否开单） */
    public function ownership(int $customerId): array
    {
        $row = Db::name('lg_lin-crm_customer')->whereNull('deleted_at')
            ->where('id', $customerId)
            ->field('id,owner_id,pool_status,protect_until')
            ->find();
        if (!$row) {
            throw new \RuntimeException('customer not found: ' . $customerId);
        }
        return $row;
    }
}
