<?php

declare(strict_types=1);

namespace linhub\host\manifest;

/**
 * manifest 校验上下文（T1.1）
 *
 * 携带校验所需的平台侧环境：宿主版本、已装包依赖图、已占用表、可替换页面清单、安全探活清单。
 * 调用方（安装/加载流程）负责提供；缺失的环境用默认空值。
 */
final class ManifestContext
{
    /**
     * @param string $hostVersion 宿主版本（如 0.1.0）
     * @param array<string,string> $installed 已装包名 => 版本
     * @param array<string,array<string,string>> $dependencyGraph 已装包依赖图：包名 => [依赖包名前缀 => 约束]
     * @param string[] $occupiedTables 已占用表名
     * @param string[] $replaceablePages 平台可替换页面清单
     * @param string[] $safeProbeRoutes 允许 sign=none 的安全探活路径（如 /public/health）
     * @param array<string,array{class:string,version:string,source:string}> $packageServices 已装功能包 provides 镜像（用于 requires 回落 / provides 冲突判定）
     */
    public function __construct(
        public readonly string $hostVersion = '0.1.0',
        public readonly array $installed = [],
        public readonly array $dependencyGraph = [],
        public readonly array $occupiedTables = [],
        public readonly array $replaceablePages = [],
        public readonly array $safeProbeRoutes = [],
        public readonly array $packageServices = [],
    ) {
    }
}