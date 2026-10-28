<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\Service;

use linhub\host\communication\HostServiceApi;
use linhub\host\services\translation\Translator;
use think\facade\App;

/**
 * 状态中文标签助手：统一经平台翻译服务（translation）输出状态文案。
 *
 * - 词条来源：包根 translations/zh-CN.php 种子，安装/升级时由宿主导入 lg_mgt_translation（namespace=lin-trade）；
 * - 读取：Translator 契约 map() 按 (namespace, lang) 一次拉全量，服务内部带 60s 进程内静态缓存，
 *   本类再按请求缓存，避免逐行查询；
 * - 兜底：服务不可用或词条缺失时回退原始状态值（英文枚举 / 1、0），页面不露 key。
 */
final class StatusLabels
{
    private const PACKAGE = 'lin-trade';

    /** @var array<string, string>|null key => 译文（请求内缓存） */
    private static ?array $map = null;

    /**
     * 状态标签：key 规范 <context>.status.<value>。
     * 如 status('purchase_order', 'draft')、status('common', 1)。
     */
    public function status(string $context, int|string|null $value): string
    {
        $v = (string) ($value ?? '');
        return $v === '' ? '' : $this->text($context . '.status.' . $v, $v);
    }

    /**
     * 通用字段标签：key 规范 <context>.<field>.<value>。
     * 如 field('sale_return', 'check_result', 'passed')。
     */
    public function field(string $context, string $field, int|string|null $value): string
    {
        $v = (string) ($value ?? '');
        return $v === '' ? '' : $this->text($context . '.' . $field . '.' . $v, $v);
    }

    /** 单 key 查询；服务不可用或词条缺失时回退 $fallback。 */
    public function text(string $key, string $fallback = ''): string
    {
        $map = self::loadMap();
        return $map[$key] ?? $fallback;
    }

    /** @return array<string, string> key => 译文（服务不可用时为空数组） */
    private static function loadMap(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        try {
            /** @var Translator $translator */
            $translator = HostServiceApi::getService(App::getInstance(), 'translation', self::PACKAGE);
            self::$map = $translator->map(self::PACKAGE, self::PACKAGE, 'zh-CN');
        } catch (\Throwable $e) {
            // 翻译服务不可用时降级为原始值显示，不阻断业务接口
            error_log('[trade] translation service unavailable: ' . $e->getMessage());
            self::$map = [];
        }
        return self::$map;
    }
}
