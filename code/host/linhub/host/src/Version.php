<?php

declare(strict_types=1);

namespace linhub\host;

/**
 * 平台版本号机制（T0.2 / I1）
 *
 * 维护宿主自身版本号常量并提供读取接口，作为 I2/I3 平台兼容校验、
 * T3 schema 迁移基线的取值来源（开放点 T9 定稿为 v0.1.0）。
 *
 * 面向功能包开发者的公开稳定 API：
 *
 * ```php
 * use linhub\host\Version;
 *
 * $version = Version::current();          // 当前平台版本号，如 '0.1.0'
 * ```
 *
 * 该类位于 linhub/host 包，功能包运行在宿主进程内，宿主已 require linhub/host，
 * 故功能包代码可直接 `use linhub\host\Version;` 调用，无需额外依赖或引导。
 * 主版本号保持向后兼容承诺；非 major 升级不会移除或改变以下公开方法签名。
 */
class Version
{
    /**
     * 宿主平台当前版本号。
     * 语义化版本：主.次.修订。T9 评审定稿初值为 v0.1.0。
     */
    public const VERSION = '0.1.0';

    /**
     * 读取平台当前版本号。
     *
     * 供功能包代码获取当前 LinHub 平台版本（如做版本特性门控、问题上报附带版本等）。
     *
     * @return string 语义化版本号 主.次.修订，如 '0.1.0'
     */
    public static function current(): string
    {
        return self::VERSION;
    }

    /**
     * 判断给定版本是否可被当前宿主兼容（简单主版本一致即视为兼容）。
     * 供 I2/I3 平台兼容校验预留，后续可充实升级策略。
     *
     * 注意：仅做主版本粗粒度判断。功能包若需按语义化约束（如 ^1.2、>=1.0 <2.0）门控，
     * 应优先在 manifest.platform.host 声明约束，由安装期 PlatformChecker 精确校验。
     */
    public static function compatible(string $requiredMajor): bool
    {
        $currentMajor = (int) explode('.', self::VERSION)[0];
        return $currentMajor === (int) $requiredMajor;
    }
}