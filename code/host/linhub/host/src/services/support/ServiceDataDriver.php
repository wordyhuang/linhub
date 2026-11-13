<?php

declare(strict_types=1);

namespace linhub\host\services\support;

/**
 * 服务数据随包驱动契约（数据随包导出与导入方案 V1.6 / 5.1）
 *
 * 平台服务若支持「数据随包导出与导入」，需实现本契约并在 services.php 条目
 * 声明 data_driver 类名；未声明的服务不参与数据打包（白名单机制）。
 *
 * 三档导出范围（scope）：
 *  - seed ：仅导出基线数据（公开项/结构化配置基线），不含运行态增量
 *  - all  ：导出该包在此服务下的全部数据
 *  - range：按时间范围导出运行态数据（仅 has_runtime=true 且声明支持的驱动）
 *
 * 实现约束：
 *  - export/import 的数据行结构由各驱动自定义，但必须可 JSON 序列化且自包含
 *    （导入时不得依赖包外的运行时上下文）。
 *  - import 的 namespace/owner_pkg 归属一律强制为 $packageKey（包 short），
 *    严禁信任导出文件中的归属字段，杜绝跨包数据污染。
 *  - purge 仅清除 $packageKey 归属数据，不得触碰其他包或宿主数据。
 */
interface ServiceDataDriver
{
    /**
     * 声明本服务的数据随包能力。
     *
     * @return array{scopes: list<string>, has_runtime: bool}
     *         scopes：支持的导出范围（seed/all/range 子集）；has_runtime：是否存在随时间增长的运行态数据
     */
    public function capabilities(): array;

    /**
     * 导出数据（打包期调用，由 PlatformDataPacker 编排）。
     *
     * @param string      $packageKey 功能包短名（= 服务侧 namespace/owner_pkg）
     * @param string      $scope      导出范围：seed | all | range（调用方已按 capabilities 过滤）
     * @param array|null  $range      scope=range 时传入 ['from' => 'Y-m-d', 'to' => 'Y-m-d']，否则 null
     * @param int         $limit      行数上限（超出即截断并置 truncated，避免包体积失控）
     * @return array{rows: list<array<string,mixed>>, truncated: bool}
     */
    public function export(string $packageKey, string $scope, ?array $range, int $limit): array;

    /**
     * 导入数据（安装/升级期调用，由 PlatformDataImporter 编排）。
     *
     * @param string $packageKey 功能包短名（导入数据强制归属此包）
     * @param array  $rows       导出文件中的行集
     * @param string $mode       merge=按唯一键 upsert；replace=先清空该包该服务已有数据再写入
     * @return int 实际写入（含更新）行数
     */
    public function import(string $packageKey, array $rows, string $mode): int;

    /**
     * 卸载清除（remove_on_uninstall=true 时调用）。返回清除行数。
     */
    public function purge(string $packageKey): int;
}
