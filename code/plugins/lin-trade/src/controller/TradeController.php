<?php

declare(strict_types=1);

namespace Lg\Pack\Trade\controller;

use Lg\Pack\Trade\Service\StatusLabels;
use think\facade\Request;
use think\facade\View;
use think\Response;

/**
 * 林销（进销存）功能包控制器基类。
 *
 * 统一 JSON 返回协议（与宿主 workdesktop-ai 约定兼容）：
 *  code >= 0 成功，负数失败；HTTP 状态统一 200，业务码通过 code 表达。
 */
abstract class TradeController
{
    /** 当前登录用户 id（可被宿主中间件注入；无则取 0）。 */
    protected function uid(): int
    {
        return (int) Request::post('user_id', 0);
    }

    /** 操作人标识。 */
    protected function operator(): string
    {
        return (string) ($this->uid() ?: 'system');
    }

    /**
     * 读取筛选参数：兼容嵌套 param（官方 wd-data-grid 卷进 `param` 的 query/body）
     * 与旧平铺参数两种写法，param 取值优先。
     */
    protected function arg(string $key, $default = null): mixed
    {
        $p  = Request::param();
        $pa = is_array($p['param'] ?? null) ? $p['param'] : [];
        return array_key_exists($key, $pa) ? $pa[$key] : ($p[$key] ?? $default);
    }

    /** 解析分页参数（双兼容：嵌套 param / 平铺 / 官方 currentPage）。 */
    protected function pageArgs(): array
    {
        return [
            'page'     => (int) $this->arg('page', (int) $this->arg('currentPage', 1)),
            'pageSize' => (int) $this->arg('pageSize', 20),
            'keyword'  => (string) $this->arg('keyword', ''),
            'status'   => (string) $this->arg('status', ''),
        ];
    }

    /**
     * 状态标签助手：走平台翻译服务（namespace=lin-trade）。
     * 词条缺失或服务不可用时回退原始值，不阻断业务接口。
     */
    protected function labels(): StatusLabels
    {
        return new StatusLabels();
    }

    /** 为行集附加 status_label（key: <context>.status.<value>）。 */
    protected function withStatusLabels(array $rows, string $context): array
    {
        $labels = $this->labels();
        foreach ($rows as &$row) {
            $row['status_label'] = $labels->status($context, $row['status'] ?? null);
        }
        unset($row);
        return $rows;
    }

    protected function jsonOk(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 0, 'data' => $data, 'message' => $message]);
    }

    protected function jsonFail(string $message, int $code = 500): Response
    {
        return json(['code' => $code, 'data' => null, 'message' => $message]);
    }

    /**
     * 输出功能包页面（iframe 直接加载 route 时返回 HTML 而非 JSON）。
     *
     * 页面为纯 HTML + 前端 JS 的静态资源，位于包内 src/view/<module>/<page>.html，
     * 直接读取返回，避免依赖宿主静态站点与 Think 模板引擎。
     */
    protected function render(string $template, array $data = []): Response
    {
        $viewPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        $file = $viewPath . $template . '.html';

        // 功能包页面为纯 HTML + 前端 JS，无服务端模板变量。
        // Think 模板引擎会把 JS 里的 `{load()}` 误判为 load 标签 => 直接读取返回，绕过引擎解析。
        if (is_file($file)) {
            $html = file_get_contents($file);
            return response($html)->contentType('text/html; charset=utf-8');
        }

        // 兜底：模板文件缺失时回退到模板引擎（便于排查路径问题）
        View::engine()->config(['view_path' => $viewPath]);
        $html = View::fetch('/' . $template, $data);

        return response($html)->contentType('text/html; charset=utf-8');
    }

    /**
     * CSV 下载响应（带 UTF-8 BOM，Excel 直接打开不乱码）。
     *
     * 按 $header 顺序取值导出，无表头时按首行键名导出。
     *
     * @param array  $rows    数据行（二维关联数组）
     * @param array  $header  表头（一维中文列名数组）
     * @param string $filename 下载文件名（不含扩展名）
     */
    protected function csvResponse(array $rows, array $header = [], string $filename = 'export'): Response
    {
        $keys  = $header ? array_keys($header) : (isset($rows[0]) ? array_keys($rows[0]) : []);
        $list  = array_values($header); // 表头文字行（无表头时为空）
        $fh    = fopen('php://temp', 'r+');
        if ($list) {
            fputcsv($fh, $list);
        }
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $k) {
                $line[] = is_array($row) ? (string) ($row[$k] ?? '') : '';
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($fh);
        fclose($fh);

        $name = trim($filename, '/\\ ') ?: 'export';
        return response($csv, 200, ['Content-Disposition' => 'attachment; filename="' . $name . '.csv"'])
            ->contentType('text/csv; charset=utf-8');
    }
}