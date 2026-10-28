<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

use linhub\host\manifest\VersionConstraint;

/**
 * 平台兼容校验（T2.3 / I2/I3）
 *
 * 校验 manifest.platform.host 与当前宿主版本是否兼容（复用 VersionConstraint）。
 * 版本不满足时返回错误信息，安装/升级前调用以阻止继续。
 */
final class PlatformChecker
{
    /** @var string 宿主版本 */
    private string $hostVersion;

    public function __construct(string $hostVersion)
    {
        $this->hostVersion = $hostVersion;
    }

    /**
     * 校验平台声明。
     *
     * @param array $platform manifest.platform 数组（含 host 约束）
     * @return string|null 兼容返回 null，否则返回错误信息
     */
    public function check(array $platform): ?string
    {
        $hostConstraint = (string) ($platform['host'] ?? '');
        if ($hostConstraint === '') {
            return null; // 未声明 host 约束视为兼容
        }
        if (VersionConstraint::matches($this->hostVersion, $hostConstraint)) {
            return null;
        }
        return "平台不兼容：当前宿主版本 {$this->hostVersion} 不满足约束 {$hostConstraint}";
    }
}