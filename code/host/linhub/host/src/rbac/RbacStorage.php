<?php

declare(strict_types=1);

namespace linhub\host\rbac;

/**
 * RBAC 数据分层存储（S5 持久化）
 *
 * 以单个 JSON 文件存放角色 / 权限组 / 用户及其关联关系，供各 Rbac 管理器读写共享。
 * 提供分区读写与整体导出/导入（供归档还原复用）。存储路径由构造传入，不硬编码。
 */
class RbacStorage
{
    /** 分区键 */
    public const ROLES      = 'roles';       // roleId => 角色
    public const GROUPS     = 'groups';      // groupId => 权限组
    public const USERS      = 'users';       // userId => 用户
    public const USER_ROLES = 'user_roles';  // userId => [roleId]
    public const USER_GROUPS= 'user_groups';  // userId => [groupId]

    private JsonFileStore $store;

    /**
     * @param string|null $file JSON 存储路径。
     *        仅记录在一个文件的文件实现使用；DB 落库子类 {@see DbRbacStorage}
     *        无需文件路径，但为兼容上层 `RbacStorage $store` 类型约束仍保留该签名。
     */
    public function __construct(?string $file = null)
    {
        $this->store = new JsonFileStore($file ?? sys_get_temp_dir() . '/rbac_storage_fallback.json');
    }

    /** 读取一个分区。 */
    public function section(string $key): array
    {
        return $this->store->section($key);
    }

    /** 覆盖写入一个分区。 */
    public function putSection(string $key, array $value): void
    {
        $this->store->putSection($key, $value);
    }

    /** 读取单个实体。 */
    public function find(string $key, string $id): ?array
    {
        $v = $this->section($key)[$id] ?? null;
        return is_array($v) ? $v : null;
    }

    /** 写入单个实体（按 id 归并）。 */
    public function put(string $key, string $id, array $record): void
    {
        $section        = $this->section($key);
        $section[$id]   = $record;
        $this->putSection($key, $section);
    }

    /** 删除单个实体。 */
    public function remove(string $key, string $id): void
    {
        $section = $this->section($key);
        unset($section[$id]);
        $this->putSection($key, $section);
    }

    /** 导出全部 rbac 数据（归档/备份用）。 */
    public function exportAll(): array
    {
        $data = $this->store->read();
        $keep = [self::ROLES, self::GROUPS, self::USERS, self::USER_ROLES, self::USER_GROUPS];
        return array_intersect_key($data, array_flip($keep));
    }

    /** 整体覆盖导入（还原用）。 */
    public function importAll(array $data): void
    {
        $keep = [self::ROLES, self::GROUPS, self::USERS, self::USER_ROLES, self::USER_GROUPS];
        $this->store->write(array_intersect_key($data, array_flip($keep)));
    }
}