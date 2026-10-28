<?php

declare(strict_types=1);

namespace linhub\host\services\translation;

/**
 * 翻译服务契约（TR-1 ~ TR-6）。
 *
 * 定位：数据值别名展示（status='pending' → 「待支付」）与页面文案多语言化的
 * 集中托管与后端查询翻译。与数据字典（dictionary）的区别：翻译有 lang 维度，
 * 读路径为「按 (namespace, lang) 拉全量 map + 回退合并」，字典为键值持久化。
 *
 * 调用方语义：
 *  - $package = null：静态回溯调用链判定 caller 所属功能包（消费侧默认形态）；
 *  - 写操作：仅允许 caller 写自己包的 namespace；超管 asAdmin 可写任意；
 *  - 读操作：本包 namespace 全量可读，他包仅 is_public=1 可读，host 命名空间任何包可读；
 *  - lang 不猜不嗅探，一律显式传参（BCP-47 简码，如 zh-CN / en-US）。
 */
interface Translator
{
    /**
     * 单条翻译：回退链 + {name} 占位符替换。
     * miss 时依次尝试 lang → fallbackLang → 返回 key 原文（页面永不露空值）。
     * 未提供的占位符保留原样。
     */
    public function translate(
        ?string $package,
        string $namespace,
        string $key,
        string $lang,
        array $params = [],
        string $fallbackLang = 'zh-CN'
    ): string;

    /**
     * 批量翻译：返回 key => 译文 map（含回退），一次 IO 消灭 N+1。
     */
    public function translateBatch(
        ?string $package,
        string $namespace,
        array $keys,
        string $lang,
        string $fallbackLang = 'zh-CN'
    ): array;

    /**
     * 全量 map：调用方可见范围内本语言词条 key => value（静态缓存命中）。
     */
    public function map(?string $package, string $namespace, string $lang): array;

    /**
     * 写词条（仅本包命名空间；超管 asAdmin 可写任意）。meta 支持 is_public。
     */
    public function put(
        ?string $package,
        string $key,
        string $lang,
        string $value,
        array $meta = [],
        bool $asAdmin = false
    ): array;

    /**
     * 删词条（本包自查自删；他包删除仅 asAdmin）。
     */
    public function remove(?string $package, string $key, string $lang, bool $asAdmin = false): bool;

    /**
     * 后台检索：filter 支持 namespace / lang / keyword 模糊，分页返回。仅超管。
     */
    public function search(array $filter, int $page = 1, int $size = 20): array;
}
