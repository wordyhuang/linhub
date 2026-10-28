<?php

declare(strict_types=1);

namespace linhub\host\services\recyclebin;

/**
 * 回收站服务契约（平台第 16 个公共服务，RB）。
 *
 * 定位：功能包删除业务数据前，将任意结构的数据快照放入回收站托管；
 * 此后可恢复（取回数据、自行写回原表）或彻底删除；支持保留期与配额；
 * 包卸载时数据保留并标记孤儿态，重装同名包自动接管。
 *
 * 调用方语义（与 Translator/Dictionary 一致）：
 *  - $package = null：静态回溯调用链，判定 caller 所属功能包——消费侧默认形态；
 *  - 写 / 删 / 恢复操作：仅允许 caller 操作自己包的 namespace（他包操作抛 RecycleBinDenied）；
 *    asAdmin = true 要求超管权限（host:recyclebin:manage），可操作任意 namespace；
 *  - 读操作：本包 namespace 全量可读；他包条目一律不可读（回收站数据天然全私有，无公开概念）；
 *  - host 保留命名空间：仅平台自身可读写，功能包不可写、不可读。
 *
 * 条目标识：put 返回条目 token（32 字符随机 hex），后续 get/restore/purge 均以 token 定位。
 */
interface RecycleBin
{
    /**
     * 入站托管：任意结构 payload（可 json_encode 的任意值），返回条目 token。
     *
     * options:
     *  - itemKey  string 业务键（可选，包自定义，如同一条数据的业务编号）
     *  - title    string 展示标题（可选，管理列表可读性）
     *  - meta     array  附加元信息（可选，JSON 存储，原样取回）
     *  - ttlDays  int    保留天数（可选；缺省/0 = 永久保留）
     *
     * @throws RecycleBinQuota payload 超过 1MB 或该包条目数达到上限
     */
    public function put(?string $package, string $sourceTable, string|int $sourcePk,
        mixed $payload, array $options = [], bool $asAdmin = false): string;

    /** 取单条（payload 已 json_decode；不存在返回 null；他包条目抛 RecycleBinDenied）。 */
    public function get(?string $package, string $token, bool $asAdmin = false): ?array;

    /**
     * 检索分页（本包视角；filter.namespace 仅超管跨包检索用）。
     * filter 支持 namespace / source_table / item_key / keyword（title/item_key/source_pk 模糊） / orphaned。
     */
    public function list(?string $package, array $filter, int $page = 1, int $size = 20,
        bool $asAdmin = false): array;

    /**
     * 恢复：返回条目数据副本（payload + 溯源字段），条目保留不删除；
     * 写回由包自己完成（主键冲突、结构漂移等由包处理）。条目不存在抛异常。
     */
    public function restore(?string $package, string $token, bool $asAdmin = false): array;

    /** 彻底删除单条；条目不存在返回 false。 */
    public function purge(?string $package, string $token, bool $asAdmin = false): bool;

    /** 批量彻底删除，返回实际删除条数（任一条目无权操作时整批拒绝）。 */
    public function purgeBatch(?string $package, array $tokens, bool $asAdmin = false): int;

    /** 统计：总数 / 孤儿数 / 7 日内到期数 / 按命名空间聚合（管理 GUI 视角）。 */
    public function stats(): array;

    /** 清理到期条目：$package=null 且 asAdmin 清全部命名空间；返回清理条数。 */
    public function purgeExpired(?string $package = null, bool $asAdmin = false): int;
}
