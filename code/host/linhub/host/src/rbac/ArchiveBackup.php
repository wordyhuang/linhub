<?php

declare(strict_types=1);

namespace linhub\host\rbac;

/**
 * 数据归档备份（T5.8 / B5）
 *
 * 归档与备份策略配置（是否启用、保留份数、目标目录）与执行入口：
 * 把 rbac 数据归档为文件快照（JSON），可列出、可还原，按保留份数清理旧快照。
 * 快照索引与策略持久化于一个 JSON 文件，快照正文写入目标目录。
 */
final class ArchiveBackup
{
    private RbacStorage $rbac;

    private JsonFileStore $meta;

    /** 当前策略（内存态 + 持久化）。 */
    private array $policy;

    public function __construct(RbacStorage $rbac, string $metaFile, array $policy = [])
    {
        $this->rbac = $rbac;
        $this->meta = new JsonFileStore($metaFile);
        $this->policy = $policy + ['enabled' => true, 'retain' => 5, 'dir' => dirname($metaFile) . '/backups'];
    }

    /** 读取/更新策略配置。 */
    public function policy(?array $newPc = null): array
    {
        if ($newPc !== null) {
            $this->policy = [
                'enabled' => (bool) ($newPc['enabled'] ?? true),
                'retain'  => (int) ($newPc['retain'] ?? $this->policy['retain']),
                'dir'     => (string) ($newPc['dir'] ?? $this->policy['dir']),
            ];
            $this->saveMeta($this->readMeta());
        }
        return $this->policy;
    }

    /**
     * 执行归档：导出 rbac 数据为文件快照，返回快照记录。
     */
    public function archive(): array
    {
        if (empty($this->policy['enabled'])) {
            throw new \RuntimeException('归档未启用');
        }
        $dir = (string) $this->policy['dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $snapshotId = date('YmdHis') . '_' . substr(md5((string) microtime(true)), 0, 6);
        $file = $dir . '/snapshot_' . $snapshotId . '.json';
        file_put_contents($file, json_encode($this->rbac->exportAll(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $record = [
            'id'         => $snapshotId,
            'file'       => $file,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $meta = $this->readMeta();
        $meta['snapshots'][$snapshotId] = $record;
        $this->writeMeta($meta);

        // 按保留份数清理旧快照
        $keep = max(1, (int) $this->policy['retain']);
        $snap = $meta['snapshots'] ?? [];
        if (count($snap) > $keep) {
            $sorted = array_keys($snap);
            sort($sorted);
            $redundant = array_slice($sorted, 0, count($snap) - $keep);
            foreach ($redundant as $sid) {
                if (is_file($meta['snapshots'][$sid]['file'] ?? '')) {
                    @unlink((string) $meta['snapshots'][$sid]['file']);
                }
                unset($meta['snapshots'][$sid]);
            }
            $this->writeMeta($meta);
        }
        return $record;
    }

    /** 列出全部快照。 */
    public function list(): array
    {
        return array_values($this->readMeta()['snapshots'] ?? []);
    }

    /** 还原：从快照导入 rbac 数据到存储。 */
    public function restore(string $snapshotId): bool
    {
        $meta = $this->readMeta();
        if (!isset($meta['snapshots'][$snapshotId])) {
            return false;
        }
        $file = (string) $meta['snapshots'][$snapshotId]['file'];
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return false;
        }
        $this->rbac->importAll($data);
        return true;
    }

    private function readMeta(): array
    {
        $d = $this->meta->read();
        if (!isset($d['policy'])) {
            $d['policy'] = $this->policy;
        }
        $d['snapshots'] = is_array($d['snapshots'] ?? null) ? $d['snapshots'] : [];
        return $d;
    }

    private function writeMeta(array $meta): void
    {
        $meta['policy'] = $this->policy;
        $this->meta->write($meta);
    }

    private function saveMeta(array $meta): void
    {
        $this->writeMeta($meta);
    }
}