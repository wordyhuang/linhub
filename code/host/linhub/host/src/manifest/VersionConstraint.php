<?php

declare(strict_types=1);

namespace linhub\host\manifest;

/**
 * 语义化版本与约束匹配（T1.1 / T2.3）。
 *
 * 支持 Composer 风格约束：完全匹配 '1.2.0'、区间 '>=1.0,<2.0'、^、~。
 * 满足 M002（版本格式）与 M004/M003（约束匹配）的判定基础。
 */
final class VersionConstraint
{
    private function __construct()
    {
    }

    /** 判断字符串是否为合法语义化版本。 */
    public static function isSemVer(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/', trim($version));
    }

    /** 解析约束为 [(type, op, major, minor, patch)] 分段。 */
    private static function parse(string $constraint): array
    {
        $parts = explode(',', str_replace(' ', '', $constraint));
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '*') {
                continue;
            }
            // 支持 x.y.z、x.y、x 三种粒度（如 ^1.2、~1.2、>=0.5）
            if (preg_match('/^(\^|~|>=|<=|==|=|>|<)?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $part, $m)) {
                $op = $m[1] !== '' ? $m[1] : '==';
                $out[] = [
                    'op' => $op,
                    'major' => (int) $m[2],
                    'minor' => (int) ($m[3] ?? -1),
                    'patch' => (int) ($m[4] ?? -1),
                ];
            }
        }
        return $out;
    }

    /**
     * 判断 $version 是否满足 $constraint。
     *
     * @param string $constraint 如 '>=0.1.0,<1.0' / '^1.2' / '~1.2.3' / '1.2.0' / '*'
     */
    public static function matches(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        if (!self::isSemVer($version)) {
            return false;
        }
        $segments = self::parse($constraint);
        if ($segments === []) {
            return false;
        }
        // 逗号分段为 AND：任一段不满足即整体不满足
        foreach ($segments as $seg) {
            if (!self::segmentMatches($version, $seg)) {
                return false;
            }
        }
        return true;
    }

    private static function segmentMatches(string $version, array $seg): bool
    {
        [$vmaj, $vmin, $vpat] = array_map('intval', explode('.', $version));
        $sMaj = $seg['major'];
        $sMin = $seg['minor'];
        $sPat = $seg['patch'];

        return match ($seg['op']) {
            '==' => $vmaj === $sMaj
                && ($sMin === -1 || $vmin === $sMin)
                && ($sPat === -1 || $vpat === $sPat),
            '>'  => [$sMaj, ($sMin < 0 ? 0 : $sMin), ($sPat < 0 ? 0 : $sPat)] < [$vmaj, $vmin, $vpat],
            '<'  => [$vmaj, $vmin, $vpat] < [$sMaj, ($sMin < 0 ? 0 : $sMin), ($sPat < 0 ? 0 : $sPat)],
            '>=' => [$vmaj, $vmin, $vpat] >= [$sMaj, ($sMin < 0 ? 0 : $sMin), ($sPat < 0 ? 0 : $sPat)],
            '<=' => [$vmaj, $vmin, $vpat] <= [$sMaj, ($sMin < 0 ? 0 : $sMin), ($sPat < 0 ? 0 : $sPat)],
            '^'  => self::matchCaret($vmaj, $vmin, $vpat, $sMaj, $sMin, $sPat),
            '~'  => self::matchTilde($vmaj, $vmin, $vpat, $sMaj, $sMin, $sPat),
            default => false,
        };
    }

    /** ^x.y.z：>=x.y.z 且 < (x+1).0.0（x=0 时 < 0.(y+1).0）；缺失段按 0 补齐。 */
    private static function matchCaret(int $vMaj, int $vMin, int $vPat, int $sMaj, int $sMin, int $sPat): bool
    {
        $sMin = $sMin < 0 ? 0 : $sMin;
        $sPat = $sPat < 0 ? 0 : $sPat;
        $lower = ([$vMaj, $vMin, $vPat] >= [$sMaj, $sMin, $sPat]);
        $upper = $sMaj === 0
            ? [$vMaj, $vMin, $vPat] < [0, $sMin + 1, 0]
            : [$vMaj, $vMin, $vPat] < [$sMaj + 1, 0, 0];
        return $lower && $upper;
    }

    /** ~x.y.z：>=x.y.z 且 < x.(y+1).0；缺失段按 0 补齐。 */
    private static function matchTilde(int $vMaj, int $vMin, int $vPat, int $sMaj, int $sMin, int $sPat): bool
    {
        $sMin = $sMin < 0 ? 0 : $sMin;
        $sPat = $sPat < 0 ? 0 : $sPat;
        return [$vMaj, $vMin, $vPat] >= [$sMaj, $sMin, $sPat]
            && [$vMaj, $vMin, $vPat] < [$sMaj, $sMin + 1, 0];
    }
}