<?php

declare(strict_types=1);

namespace linhub\host\services\scheduler;

use linhub\host\services\scheduler\exception\TaskInvalidSchedule;

/**
 * 周期表达式解析：interval（秒）与五段 cron（分 时 日 月 周）。
 *
 * 支持 *、a、a-b、a-b/c、* /n、逗号列表；周字段 0 与 7 都表示周日。
 * 触发时间统一按 UTC 计算并输出 ISO 8601（带 +00:00 偏移），
 * 保证字符串比较与当前时间判断始终一致；timezone 仅用于解释 cron 的日历语义。
 */
final class ScheduleExpression
{
    private const MINUTE = 0;

    /** 校验表达式；非法抛 TaskInvalidSchedule。 */
    public static function validate(string $mode, string $expr): void
    {
        if ($mode === 'interval') {
            if (!preg_match('/^\d+$/', trim($expr)) || (int) $expr < 1) {
                throw new TaskInvalidSchedule('interval 表达式必须是正整数秒：' . $expr);
            }
            return;
        }
        if ($mode !== 'cron') {
            throw new TaskInvalidSchedule('不支持的周期模式：' . $mode);
        }
        $parts = preg_split('/\s+/', trim($expr));
        if ($parts === false || count($parts) !== 5) {
            throw new TaskInvalidSchedule('cron 表达式必须是五段：' . $expr);
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
        foreach ($parts as $index => $part) {
            self::parseField($part, $ranges[$index][0], $ranges[$index][1], $index === 4);
        }
    }

    /**
     * 计算下一次触发时间（ISO 8601 UTC）。
     *
     * @param string $fromIso 起点（通常是 now 或上次计划时间）
     */
    public static function next(string $mode, string $expr, string $fromIso, string $timezone = 'UTC'): string
    {
        $from = new \DateTimeImmutable($fromIso, self::zone('UTC'));
        if ($mode === 'interval') {
            self::validate($mode, $expr);
            return $from->modify('+' . (int) $expr . ' seconds')
                ->setTimezone(self::defaultZone())
                ->format('c');
        }
        self::validate($mode, $expr);
        $zone = self::zone($timezone);
        $local = $from->setTimezone($zone);
        $cursor = $local->setTime((int) $local->format('H'), (int) $local->format('i'), 0)->modify('+1 minute');
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        $minutes = self::parseField($parts[0], 0, 59, false);
        $hours = self::parseField($parts[1], 0, 23, false);
        $days = self::parseField($parts[2], 1, 31, false);
        $months = self::parseField($parts[3], 1, 12, false);
        $weekdays = self::parseField($parts[4], 0, 6, true);
        $domAny = trim($parts[2]) === '*';
        $dowAny = trim($parts[4]) === '*';
        for ($i = 0; $i < 527040; $i++) {
            $minute = (int) $cursor->format('i');
            $hour = (int) $cursor->format('H');
            $day = (int) $cursor->format('d');
            $month = (int) $cursor->format('n');
            $weekday = (int) $cursor->format('w');
            $dayHit = $domAny || $dowAny
                ? (($domAny || in_array($day, $days, true)) && ($dowAny || in_array($weekday, $weekdays, true)))
                : (in_array($day, $days, true) || in_array($weekday, $weekdays, true));
            if (
                in_array($minute, $minutes, true)
                && in_array($hour, $hours, true)
                && in_array($month, $months, true)
                && $dayHit
            ) {
                return $cursor->setTimezone(self::defaultZone())->format('c');
            }
            $cursor = $cursor->modify('+1 minute');
        }
        throw new TaskInvalidSchedule('cron 表达式在 366 天内无触发时间：' . $expr);
    }

    /** 解析单个字段为取值数组；非法抛 TaskInvalidSchedule。 */
    private static function parseField(string $field, int $min, int $max, bool $normalizeWeekday): array
    {
        $items = [];
        foreach (explode(',', trim($field)) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                throw new TaskInvalidSchedule('cron 字段存在空值');
            }
            $step = 1;
            if (str_contains($segment, '/')) {
                [$segment, $stepText] = explode('/', $segment, 2);
                if (!preg_match('/^\d+$/', $stepText) || (int) $stepText < 1) {
                    throw new TaskInvalidSchedule('cron 步长非法：' . $field);
                }
                $step = (int) $stepText;
            }
            if ($segment === '*') {
                $start = $min;
                $end = $max;
            } elseif (str_contains($segment, '-')) {
                [$startText, $endText] = explode('-', $segment, 2);
                if (!self::isInt($startText) || !self::isInt($endText)) {
                    throw new TaskInvalidSchedule('cron 区间非法：' . $field);
                }
                $start = (int) $startText;
                $end = (int) $endText;
            } else {
                if (!self::isInt($segment)) {
                    throw new TaskInvalidSchedule('cron 取值非法：' . $field);
                }
                $start = (int) $segment;
                $end = $start;
            }
            if ($start < $min || $end > $max || $start > $end) {
                throw new TaskInvalidSchedule('cron 取值越界：' . $field);
            }
            for ($value = $start; $value <= $end; $value += $step) {
                if ($normalizeWeekday && $value === 7) {
                    $value = 0;
                }
                $items[] = $value;
            }
        }
        if ($items === []) {
            throw new TaskInvalidSchedule('cron 字段为空：' . $field);
        }
        return $normalizeWeekday ? array_values(array_unique($items)) : $items;
    }

    private static function isInt(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    private static function zone(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name === '' ? 'UTC' : $name);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /** 输出统一用 PHP 默认时区，保证与 PlatformStore 的 date('c') 字符串可直接比较。 */
    private static function defaultZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
