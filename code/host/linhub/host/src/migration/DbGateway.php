<?php

declare(strict_types=1);

namespace linhub\host\migration;

/**
 * 抽象 PDO 层（T3.1/R1 迁移引擎的 DB 访问门面）
 *
 * 迁移引擎不直接依赖 \PDO，而是通过本接口访问数据库，使核心逻辑可被替换。
 * - 生产环境使用 {@see PdoGateway}（包裹真实 \PDO）；
 * - 无数据库驱动的验证/测试环境使用内存实现（见测试脚本），以"内存静态断言"验证事务回滚、前缀校验、快照/差异、审计。
 * 提供表/列/索引的结构访问，支撑 schema 快照（T3.4）与差异比对。
 */
interface DbGateway
{
    /** 返回底层数据库方言（sqlite / mysql / pgsql），供建表等方言化 SQL 使用。 */
    public function dialect(): string;

    /** 执行一条不返回结果集的 SQL（DDL / DML）。 */
    public function exec(string $sql): void;

    /** 执行查询并返回所有行（关联数组列表）。 */
    public function query(string $sql): array;

    /** 返回当前库中全部表名（小写）。 */
    public function tables(): array;

    /** 返回某表的列描述：list<array{name:string,type:string,nullable:bool,primary:bool,default:mixed}>。 */
    public function columns(string $table): array;

    /** 返回某表的索引描述：list<array{name:string,columns:string[],unique:bool}>。 */
    public function indexes(string $table): array;

    /** 开启一个事务（进入事务状态；不支持嵌套时重复调用为无操作）。 */
    public function begin(): void;

    /** 提交当前事务。 */
    public function commit(): void;

    /** 回滚当前事务。 */
    public function rollback(): void;

    /** 距当前 SQL 语句的摘要（用于审计，省略字面量避免过长）。 */
    public function summarize(string $sql): string;
}