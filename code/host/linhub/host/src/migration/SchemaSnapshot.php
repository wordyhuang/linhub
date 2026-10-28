<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * schema 快照（T3.4 / R2）
 *
 * 生成库结构的抽象描述：表清单、每表的列与索引。供"发版快照"与"差异看板"使用。
 * `snapshotSchema(\PDO $db)` 为对外约定的入口（经 PdoGateway 映射）；
 * `fromGateway(DbGateway)` 为抽象层入口（供验证/任意驱动）。
 */
final class SchemaSnapshot
{
    private function __construct()
    {
    }

    /**
     * 从真实 PDO 生成快照（对外入口，验证环境优先用 fromGateway）。
     *
     * @return array{version:string,tables:array}
     */
    public static function fromPdo(\PDO $pdo): array
    {
        return self::fromGateway(new PdoGateway($pdo));
    }

    /**
     * 生成库结构快照。
     *
     * @return array{version:string,tables:array<string,array{columns:array,indexes:array}>}
     */
    public static function fromGateway(DbGateway $db): array
    {
        $tables = [];
        foreach ($db->tables() as $table) {
            $tables[$table] = [
                'columns' => $db->columns($table),
                'indexes' => $db->indexes($table),
            ];
        }
        ksort($tables);
        return ['version' => date('c'), 'tables' => $tables];
    }
}