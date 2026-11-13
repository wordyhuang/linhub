<?php

declare(strict_types=1);

namespace linhub\host\home;

use linhub\host\rbac\JsonFileStore;

/**
 * 用户个人资料存储（自定义显示名称、工作台皮肤等私人配置）。
 *
 * 结构：{userId: {name: string, skin: string}}。
 *  - name：未定制（键不存在）getName 返回 null，由调用方使用身份自带名称；
 *  - skin：未定制 getSkin 返回 null（使用默认皮肤）；保存空串即清除设置。
 *  - 保存非空名称后，页面与 session 均以自定义名称展示。
 */
final class UserProfile
{
    private JsonFileStore $store;

    public function __construct(string $file)
    {
        $this->store = new JsonFileStore($file);
    }

    /** 读取某用户自定义显示名；未定制返回 null。 */
    public function getName(string $userId): ?string
    {
        $v = $this->store->read()[$userId]['name'] ?? null;
        return $v === null ? null : (string) $v;
    }

    /** 保存（覆盖）某用户显示名；空白名称无效。 */
    public function saveName(string $userId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('显示名称不能为空');
        }
        $data = $this->store->read();
        $data[$userId]['name'] = $name;
        $this->store->write($data);
    }

    /** 读取某用户工作台皮肤；未设置返回 null（调用方使用默认皮肤）。 */
    public function getSkin(string $userId): ?string
    {
        $v = $this->store->read()[$userId]['skin'] ?? null;
        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** 保存（覆盖）某用户工作台皮肤；空串清除设置（恢复默认）。 */
    public function saveSkin(string $userId, string $skin): void
    {
        $skin = trim($skin);
        $data = $this->store->read();
        if ($skin === '') {
            unset($data[$userId]['skin']);
        } else {
            $data[$userId]['skin'] = $skin;
        }
        $this->store->write($data);
    }
}
