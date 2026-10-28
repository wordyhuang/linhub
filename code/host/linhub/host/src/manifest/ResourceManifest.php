<?php

declare(strict_types=1);

namespace linhub\host\manifest;

/**
 * 资源清单管理（T1.2 / F4 / P5）
 *
 * 从功能包 manifest 提取 `resources` 声明（database/files/others），
 * 提供清单列出、包内重复检测、与宿主已占用资源冲突检测三类能力。
 * 供安装器在资源落地前校验是否存在命名冲突（P5/F1）。
 */
final class ResourceManifest
{
    /**
     * 收集并规范化 manifest 中的资源声明。
     *
     * @param array $m manifest 数组
     * @return array{package:string,database:array,raw_database:array,files:array,others:array}
     */
    public static function collect(array $m): array
    {
        $short = (string) ($m['short'] ?? '');
        $resources = (array) ($m['resources'] ?? []);
        $resourceKeys = [];
        $dbEntries = [];
        foreach ((array) ($resources['database'] ?? []) as $raw) {
            if (is_string($raw)) {
                $t = $raw;
                $entry = ['table' => $t, 'type' => 'create'];
            } else {
                $t = (string) ($raw['table'] ?? '');
                $entry = ['table' => $t, 'type' => (string) ($raw['type'] ?? 'create')];
            }
            if ($t === '') {
                continue;
            }
            $dbEntries[] = $entry;
            $resourceKeys[] = "table:{$t}";
        }

        $files = [];
        foreach ((array) ($resources['files'] ?? []) as $f) {
            if ($f === '') {
                continue;
            }
            $files[] = (string) $f;
            $resourceKeys[] = "file:{$f}";
        }

        $others = [];
        foreach ((array) ($resources['others'] ?? []) as $o) {
            $others[] = is_array($o) ? $o : ['name' => (string) $o];
            $resourceKeys[] = 'other:' . (is_array($o) ? (string) ($o['name'] ?? '') : (string) $o);
        }

        return [
            'package'     => $short,
            'database'    => $dbEntries,
            'raw_database'=> $resourceKeys,
            'files'       => $files,
            'others'      => $others,
        ];
    }

    /**
     * 检测资源冲突，返回冲突项描述列表。
     *
     * 两类冲突：
     *  - 包内重复：同一资源在包内声明多次。
     *  - 与宿主已占用资源冲突：数据表已存在于 `$occupiedTables`。
     *
     * @param array $m manifest 数组
     * @param string[] $occupiedTables 宿主已占用表名集合
     * @return list<string>
     */
    public static function findConflicts(array $m, array $occupiedTables): array
    {
        $conflicts = [];
        $collect = self::collect($m);

        // 包内重复
        $seen = [];
        foreach ($collect['raw_database'] as $key) {
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $conflicts[] = "包内资源重复：{$key}";
            }
            $seen[$key] = true;
        }

        // 与宿主占用表冲突
        $occupied = array_flip($occupiedTables);
        foreach ($collect['database'] as $entry) {
            if (isset($occupied[$entry['table']])) {
                $conflicts[] = "数据表已占用，冲突：{$entry['table']}";
            }
        }

        return $conflicts;
    }
}