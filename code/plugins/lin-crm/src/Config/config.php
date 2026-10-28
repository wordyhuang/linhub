<?php
declare(strict_types=1);

/**
 * 林客 CRM 功能包 · 配置默认值（12 项，全部租户作用域）
 * 与《林客_产品需求清单_v1.0》附录 D 一致；宿主 config 服务装载后租户级可覆盖。
 */
return [
    'customer' => [
        'protect_days'   => 30,          // 私海保护期天数（OI-002）
        'private_limit'  => 50,          // 私海持有上限（OI-002）
        'release_run_at' => '02:00',     // 释放检查时点（AC-CUS-02-1）
    ],
    'lead' => [
        'followup_overdue_days' => 7,    // 线索超期未跟进提醒天数
    ],
    'opportunity' => [
        'win_rates'     => [10, 30, 50, 70, 100], // 五阶段默认赢率
        'stagnant_days' => 14,           // 停滞预警阈值
    ],
    'contract' => [
        'require_audit' => true,         // 合同审批开关（false=免审直达 effective）
    ],
    'receivable' => [
        'remind_offsets' => 'T-3,T-1,D+1', // 回款提醒三档
    ],
    'phone' => [
        'mask_enabled' => true,          // 手机号脱敏展示开关
    ],
    'recycle' => [
        'retention_days' => 30,          // 回收站保留天数（GR-2）
    ],
    'import' => [
        'batch_size' => 1000,            // 批量导入每批行数（NFR-PERF-05）
    ],
    'report' => [
        'cache_ttl' => 300,              // 报表聚合结果缓存秒数（OI-003）
    ],
];
