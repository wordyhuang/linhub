<?php

declare(strict_types=1);

namespace linhub\host\services\logging;

use linhub\host\rbac\JsonFileStore;
use linhub\host\support\HostBootstrap;

/**
 * 日志服务设置（运行日志开关 / 最低记录级别 / 保留月数）
 *
 *  - 存储：data/platform/log_setting.json（JsonFileStore 原子写）；
 *  - 缺省值保持历史行为：启用、最低级别 debug（即全量记录）、保留 6 个月；
 *  - LoggingService 写盘前读取本设置：关闭即不写，低于最低级别的条目丢弃；
 *  - 保留月数由管理页「立即清理」动作执行，不引入定时任务。
 */
final class LogSettingService
{
    /** 级别权重（数值越大越严重）。 */
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public const DEFAULT_MIN_LEVEL = 'debug';

    public const DEFAULT_RETENTION_MONTHS = 6;

    /** 保留月数上限（0=永久保留）。 */
    public const MAX_RETENTION_MONTHS = 60;

    private JsonFileStore $store;

    /** 单请求内缓存，避免每次写日志都读盘。 */
    private ?array $cache = null;

    private static ?self $shared = null;

    /** 注入文件路径（验证可指向 temp/）；空则走 HostBootstrap 默认 data 路径。 */
    public function __construct(?string $file = null)
    {
        if ($file === null || $file === '') {
            $file = (new HostBootstrap())->logSettingFile();
        }
        $this->store = new JsonFileStore($file);
    }

    /** 进程内共享实例（LoggingService 写盘时使用）；首次创建可指定文件路径（验证隔离用）。 */
    public static function shared(?string $file = null): self
    {
        return self::$shared ??= new self($file);
    }

    /**
     * 全部设置（含默认值与白名单归一）。
     *
     * @return array{enabled:string, min_level:string, retention_months:string, updated_at:string, updated_by:string}
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $data = $this->store->read();
        $level = strtolower(trim((string) ($data['min_level'] ?? self::DEFAULT_MIN_LEVEL)));
        if (!isset(self::LEVELS[$level])) {
            $level = self::DEFAULT_MIN_LEVEL;
        }
        $retention = (int) ($data['retention_months'] ?? self::DEFAULT_RETENTION_MONTHS);
        if ($retention < 0) {
            $retention = 0;
        }
        if ($retention > self::MAX_RETENTION_MONTHS) {
            $retention = self::MAX_RETENTION_MONTHS;
        }
        $enabled = array_key_exists('enabled', $data) && (string) $data['enabled'] === '0' ? '0' : '1';
        return $this->cache = [
            'enabled'          => $enabled,
            'min_level'        => $level,
            'retention_months' => (string) $retention,
            'updated_at'       => trim((string) ($data['updated_at'] ?? '')),
            'updated_by'       => trim((string) ($data['updated_by'] ?? '')),
        ];
    }

    public function enabled(): bool
    {
        return $this->all()['enabled'] === '1';
    }

    public function minLevel(): string
    {
        return $this->all()['min_level'];
    }

    public function retentionMonths(): int
    {
        return (int) $this->all()['retention_months'];
    }

    /** 判断某级别是否达到记录门槛（未知级别按记录处理）。 */
    public function passes(string $level): bool
    {
        $normalized = strtolower(trim($level));
        if (!isset(self::LEVELS[$normalized])) {
            return true;
        }
        return self::LEVELS[$normalized] >= self::LEVELS[$this->minLevel()];
    }

    /**
     * 保存设置（字段白名单 + 归一校验）。
     *
     * @param array<string, mixed> $data
     * @return array<string, string> 归一后的设置
     */
    public function save(array $data, string $actor = ''): array
    {
        $current = $this->all();
        if (array_key_exists('enabled', $data)) {
            $current['enabled'] = !empty($data['enabled']) && (string) $data['enabled'] !== '0' ? '1' : '0';
        }
        if (array_key_exists('min_level', $data)) {
            $level = strtolower(trim((string) $data['min_level']));
            $current['min_level'] = isset(self::LEVELS[$level]) ? $level : $current['min_level'];
        }
        if (array_key_exists('retention_months', $data)) {
            $retention = (int) $data['retention_months'];
            $current['retention_months'] = (string) max(0, min(self::MAX_RETENTION_MONTHS, $retention));
        }
        $current['updated_at'] = date('Y-m-d H:i:s');
        $current['updated_by'] = $actor;
        $this->store->write($current);
        $this->cache = null;
        return $this->all();
    }
}
