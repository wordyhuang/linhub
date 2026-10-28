<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * schema 差异比对（T3.4 / R2）
 *
 * 对比两个快照（目标发版快照 vs 实际结构），返回差异清单，
 * 覆盖新增/删除表、列、索引，以及列/索引属性变化。供"差异看板"展示。
 */
final class SchemaDiff
{
    private function __construct()
    {
    }

    /**
     * 对比 $from 快照与 $to 快照。
     *
     * @param array $from 基线快照（SchemaSnapshot 产物）
     * @param array $to   目标快照
     * @return list<array{type:string,kind:string,table:string,name:string,detail:string}>
     */
    public static function diff(array $from, array $to): array
    {
        $out = [];
        $fromTables = $from['tables'] ?? [];
        $toTables = $to['tables'] ?? [];

        // 表级
        foreach ($toTables as $table => $def) {
            if (!isset($fromTables[$table])) {
                $out[] = ['type' => 'added', 'kind' => 'table', 'table' => $table, 'name' => $table, 'detail' => '新增表'];
            }
        }
        foreach ($fromTables as $table => $def) {
            if (!isset($toTables[$table])) {
                $out[] = ['type' => 'removed', 'kind' => 'table', 'table' => $table, 'name' => $table, 'detail' => '删除表'];
            }
        }

        // 共同表的列 / 索引
        foreach ($toTables as $table => $def) {
            if (!isset($fromTables[$table])) {
                continue;
            }
            self::diffColumns($out, $fromTables[$table], $toTables[$table], $table);
            self::diffIndexes($out, $fromTables[$table], $toTables[$table], $table);
        }
        return $out;
    }

    private static function diffColumns(array &$out, array $from, array $to, string $table): void
    {
        $fromCols = self::keyBy($from['columns'] ?? [], 'name');
        $toCols = self::keyBy($to['columns'] ?? [], 'name');
        foreach ($toCols as $name => $col) {
            if (!isset($fromCols[$name])) {
                $out[] = ['type' => 'added', 'kind' => 'column', 'table' => $table, 'name' => $name, 'detail' => '新增列 ' . ($col['type'] ?? '')];
                continue;
            }
            $from = $fromCols[$name];
            if (($from['type'] ?? '') !== ($col['type'] ?? '')) {
                $out[] = ['type' => 'changed', 'kind' => 'column', 'table' => $table, 'name' => $name, 'detail' => "列类型 {$from['type']} -> {$col['type']}"];
            }
            if (($from['nullable'] ?? false) !== ($col['nullable'] ?? false)) {
                $out[] = ['type' => 'changed', 'kind' => 'column', 'table' => $table, 'name' => $name, 'detail' => '空约束变化'];
            }
        }
        foreach ($fromCols as $name => $col) {
            if (!isset($toCols[$name])) {
                $out[] = ['type' => 'removed', 'kind' => 'column', 'table' => $table, 'name' => $name, 'detail' => '删除列'];
            }
        }
    }

    private static function diffIndexes(array &$out, array $from, array $to, string $table): void
    {
        $fromIdx = self::keyBy($from['indexes'] ?? [], 'name');
        $toIdx = self::keyBy($to['indexes'] ?? [], 'name');
        foreach ($toIdx as $name => $idx) {
            if (!isset($fromIdx[$name])) {
                $out[] = ['type' => 'added', 'kind' => 'index', 'table' => $table, 'name' => $name, 'detail' => '新增索引'];
                continue;
            }
            $f = $fromIdx[$name];
            if (($f['columns'] ?? []) !== ($idx['columns'] ?? []) || ($f['unique'] ?? false) !== ($idx['unique'] ?? false)) {
                $out[] = ['type' => 'changed', 'kind' => 'index', 'table' => $table, 'name' => $name, 'detail' => '索引定义变化'];
            }
        }
        foreach ($fromIdx as $name => $idx) {
            if (!isset($toIdx[$name])) {
                $out[] = ['type' => 'removed', 'kind' => 'index', 'table' => $table, 'name' => $name, 'detail' => '删除索引'];
            }
        }
    }

    private static function keyBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) ($r[$key] ?? '')] = $r;
        }
        return $out;
    }
}