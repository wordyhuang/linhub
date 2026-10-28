<?php

declare(strict_types=1);

namespace linhub\host\manifest;

/**
 * manifest 校验错误码（T1.1 / 协议规范第七节）。
 */
final class ManifestError
{
    public const M001 = 'M001'; // 基础键 name/short/version 必填且格式合法
    public const M002 = 'M002'; // version 为语义化版本
    public const M003 = 'M003'; // 依赖无环且可解析
    public const M004 = 'M004'; // platform.host 与宿主版本兼容
    public const M005 = 'M005'; // 资源表名前缀隔离且无冲突
    public const M006 = 'M006'; // services.requires 引用服务存在（服务不存在）
    public const M007 = 'M007'; // ui_replace.target 属于可替换页面清单
    public const M008 = 'M008'; // subscribes.topic 有 lg.<short>. 前缀
    public const M009 = 'M009'; // 权限编码为 产品:资源:操作
    public const M010 = 'M010'; // access=public 必须有 access_routes 且 sign 合法
    public const M011 = 'M011'; // sign=none 仅 GET 且命中安全探活清单
    public const M012 = 'M012'; // services.requires 服务版本约束不满足
    public const M013 = 'M013'; // services.provides 条目格式非法（id/class 空或 version 非语义化）
    public const M014 = 'M014'; // services.provides id 与宿主服务冲突（host 源只读红线）

    public const ALL = [
        self::M001, self::M002, self::M003, self::M004, self::M005, self::M006,
        self::M007, self::M008, self::M009, self::M010, self::M011, self::M012,
        self::M013, self::M014,
    ];

    private function __construct()
    {
    }
}