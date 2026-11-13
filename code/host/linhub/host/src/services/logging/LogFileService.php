<?php

declare(strict_types=1);

namespace linhub\host\services\logging;

/**
 * 运行日志文件巡检（工作台统计 / 最近日志 / 过期清理）
 *
 * 日志落盘结构：runtime/log/YYYYMM/host.log（由 LoggingService 写入）。
 * 本类只读扫描 + 显式清理，不参与写日志；目录缺失时按空数据返回。
 * 构造可注入日志根目录，验证时指向 temp/ 隔离。
 */
final class LogFileService
{
    /** 最近日志读取的最大字节数（尾部截取，避免大体量文件整读）。 */
    private const TAIL_BYTES = 524288;

    /** 今日计数缓存文件（位于日志根目录，与月份目录平级）。 */
    private const COUNT_CACHE_FILE = '.today-count.json';

    public function __construct(private readonly string $root = '')
    {
    }

    /** 日志根目录（带统一分隔符）。 */
    public function root(): string
    {
        $root = $this->root !== '' ? $this->root : LoggingService::defaultLogDir();
        return rtrim(str_replace('\\', '/', $root), '/') . '/';
    }

    /**
     * 汇总统计：文件数 / 总大小 / 月度归档 / 今日各级别条数。
     *
     * @return array{root:string, files:int, bytes:int, months:list<array<string,mixed>>, today:array<string,mixed>}
     */
    public function stats(): array
    {
        clearstatcache();
        $months = [];
        $files = 0;
        $bytes = 0;
        foreach ($this->monthDirs() as $dir) {
            $logs = glob($dir . '/*.log') ?: [];
            $size = 0;
            $updated = 0;
            foreach ($logs as $file) {
                $size += (int) @filesize($file);
                $updated = max($updated, (int) @filemtime($file));
            }
            $months[] = [
                'month'      => basename($dir),
                'files'      => count($logs),
                'bytes'      => $size,
                'updated_at' => $updated > 0 ? date('Y-m-d H:i:s', $updated) : '',
            ];
            $files += count($logs);
            $bytes += $size;
        }
        usort($months, static fn (array $a, array $b): int => strcmp($b['month'], $a['month']));
        return [
            'root'   => $this->root(),
            'files'  => $files,
            'bytes'  => $bytes,
            'months' => $months,
            'today'  => $this->todayCounts(),
        ];
    }

    /**
     * 今日各日志级别条数（增量扫描：只读取上次统计之后新增的行）。
     *
     * 缓存 .today-count.json 记录文件路径、已读偏移与计数：
     *  - 同文件且偏移未超界 → 续读增量；
     *  - 日期变化 → 沿用偏移、计数归零（不重扫历史行）；
     *  - 缓存缺失/文件被清空 → 从头统计。
     *
     * @return array{date:string, total:int, debug:int, info:int, warning:int, error:int}
     */
    public function todayCounts(): array
    {
        $today = date('Y-m-d');
        $counts = ['date' => $today, 'total' => 0, 'debug' => 0, 'info' => 0, 'warning' => 0, 'error' => 0];
        $file = $this->currentLogFile();
        // 不用 is_file/filesize：同请求内 stat 缓存会给出陈旧大小，改用文件句柄定位
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return $counts;
        }
        fseek($handle, 0, SEEK_END);
        $size = (int) ftell($handle);
        $cache = $this->readCountCache();
        $sameFile = $cache['file'] === $file && (int) $cache['offset'] <= $size;
        $offset = $sameFile ? (int) $cache['offset'] : 0;
        if ($sameFile && $cache['date'] === $today) {
            foreach (['total', 'debug', 'info', 'warning', 'error'] as $key) {
                $counts[$key] = (int) ($cache['counts'][$key] ?? 0);
            }
        }
        fseek($handle, $offset);
        while (($line = fgets($handle)) !== false) {
            // 末尾未写完的行留到下次统计，避免半行计入
            if (!str_ends_with($line, "\n")) {
                break;
            }
            $offset = (int) ftell($handle);
            if (strpos($line, '[' . $today) !== 0) {
                continue;
            }
            $counts['total']++;
            if (preg_match('/^\[[^\]]+\] ([A-Za-z]+)/', $line, $m)) {
                $level = strtolower($m[1]);
                if (isset($counts[$level])) {
                    $counts[$level]++;
                }
            }
        }
        fclose($handle);
        $this->writeCountCache([
            'file'   => $file,
            'offset' => $offset,
            'date'   => $today,
            'counts' => $counts,
        ]);
        return $counts;
    }

    /**
     * 最近日志条目（新 → 旧）。
     *
     * @param int    $limit 条数（1~500）
     * @param string $level 级别过滤，空=全部
     * @return list<array{time:string, level:string, message:string, context:string}>
     */
    public function recent(int $limit = 100, string $level = ''): array
    {
        $limit = max(1, min(500, $limit));
        $level = strtolower(trim($level));
        $file = $this->currentLogFile();
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $lines = $this->tailLines($file);
        $entries = [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $entry = $this->parseLine($lines[$i]);
            if ($entry === null) {
                continue;
            }
            if ($level !== '' && $entry['level'] !== $level) {
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    /**
     * 按保留月数清理过期月份目录。
     *
     * @param int $retentionMonths 保留月数，0=永久保留（不清理）
     * @return array{retention_months:int, deleted_months:list<string>, deleted_files:int, freed_bytes:int}
     */
    public function cleanup(int $retentionMonths): array
    {
        $retentionMonths = max(0, $retentionMonths);
        $result = [
            'retention_months' => $retentionMonths,
            'deleted_months'   => [],
            'deleted_files'    => 0,
            'freed_bytes'      => 0,
        ];
        if ($retentionMonths === 0) {
            return $result;
        }
        $threshold = date('Ym', strtotime('-' . $retentionMonths . ' months'));
        foreach ($this->monthDirs() as $dir) {
            $month = basename($dir);
            if (strcmp($month, $threshold) >= 0) {
                continue;
            }
            $files = glob($dir . '/*') ?: [];
            $freed = 0;
            $removed = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    $freed += (int) @filesize($file);
                    if (@unlink($file)) {
                        $removed++;
                    }
                }
            }
            if (@rmdir($dir)) {
                $result['deleted_months'][] = $month;
                $result['deleted_files'] += $removed;
                $result['freed_bytes'] += $freed;
            }
        }
        return $result;
    }

    /**
     * 月度目录清单（仅 YYYYMM 命名，防路径穿越）。
     *
     * @return list<string>
     */
    private function monthDirs(): array
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return [];
        }
        $dirs = [];
        foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^(\d{6})$/', basename($dir))) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    private function currentLogFile(): string
    {
        return $this->root() . date('Ym') . '/host.log';
    }

    /**
     * 读取今日计数缓存（缺失或损坏按空缓存处理）。
     *
     * @return array{file:string, offset:int, date:string, counts:array<string,int>}
     */
    private function readCountCache(): array
    {
        $empty = ['file' => '', 'offset' => 0, 'date' => '', 'counts' => []];
        $raw = @file_get_contents($this->root() . self::COUNT_CACHE_FILE);
        if ($raw === false || $raw === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }
        return [
            'file'   => (string) ($data['file'] ?? ''),
            'offset' => (int) ($data['offset'] ?? 0),
            'date'   => (string) ($data['date'] ?? ''),
            'counts' => is_array($data['counts'] ?? null) ? $data['counts'] : [],
        ];
    }

    /** 写入今日计数缓存（失败静默，下次退化为全量扫描）。 */
    private function writeCountCache(array $cache): void
    {
        @file_put_contents(
            $this->root() . self::COUNT_CACHE_FILE,
            json_encode($cache, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * 读取文件尾部若干行（首行可能被截断，丢弃）。
     *
     * @return list<string>
     */
    private function tailLines(string $file): array
    {
        $size = (int) @filesize($file);
        $start = max(0, $size - self::TAIL_BYTES);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($start > 0) {
            fseek($handle, $start);
        }
        $chunk = (string) fread($handle, $size - $start);
        fclose($handle);
        $lines = explode("\n", str_replace("\r\n", "\n", $chunk));
        if ($start > 0) {
            array_shift($lines);
        }
        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * 解析一行日志：`[Y-m-d H:i:s] LEVEL | message | {json}`。
     *
     * @return array{time:string, level:string, message:string, context:string}|null
     */
    private function parseLine(string $line): ?array
    {
        if (!preg_match('/^\[([\d\-: ]+)\] ([A-Za-z]+) \| (.*)$/', $line, $m)) {
            return null;
        }
        $message = $m[3];
        $context = '';
        $pos = strrpos($message, ' | ');
        if ($pos !== false) {
            $tail = substr($message, $pos + 3);
            if ($tail === 'null' || is_array(json_decode($tail, true))) {
                $message = substr($message, 0, $pos);
                $context = $tail;
            }
        }
        return [
            'time'    => $m[1],
            'level'   => strtolower($m[2]),
            'message' => $message,
            'context' => $context,
        ];
    }
}
