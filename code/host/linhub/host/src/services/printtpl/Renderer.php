<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

/**
 * 打印模板渲染引擎（PT-14 / 5.1 占位符语法 v1 最小集）。
 *
 * 占位符语法（T-4 拍板：v1 = 点路径 + each + if；格式化占位符 v1.1）：
 *  - {{order.no}}      点路径取值，输出自动 HTML 转义；
 *  - {{{order.remark}}} 三括号不转义（仅富文本字段，超管自担风险）；
 *  - {{#each items}} … {{/each}}        列表循环，体内相对取值，{{@index}} 取序号（0 基），支持嵌套；
 *  - {{#if order.paid}} … {{else}} … {{/if}}  条件块（真值：true/非0数字/非空字符串/非空数组/非空对象）。
 *  - {{pt.page}} / {{pt.pages}}         保留字面，由打印页脚本替换为页码/总页数（PT-8）。
 *
 * 模板正文中的表格组件（可视化编辑器产物）：
 *  <div class="pt-el" data-pt="table" data-bind="items"
 *       data-cols='[{"k":"name","t":"名称","w":"40%","align":"left","subtotal":false}]'
 *       data-group="" data-merge="" data-empty="暂无数据"></div>
 * 渲染时按列定义生成 <table class="pt-table">，支持：
 *  - data-group：按列分组，段尾插入小计行（PT-8 分组小计，subtotal 列求和）；
 *  - data-merge：组内相邻同值单元格纵向合并（rowspan，PT-8 单元格合并）；
 * 跨页表头重复与页码由打印页脚本呈现（thead 原生 + JS 分页）。
 *
 * 图片组件地址（PT-19）：src 为完整 URL 或 attach://<token>（附件托管服务引用），
 * 渲染时统一解析为 /attachment/download?token=…&image=1（token 失效回退默认图片）。
 *
 * 模板正文为超管可信源（NFR-2），语法错误抛 \RuntimeException 携带位置信息。
 */
final class Renderer
{
    /** @var bool parse() 内部标记：上一个 if 主体是否终止于 {{else}} */
    private static bool $sawElse = false;

    /** @var array<string, array> 进程内编译缓存：模板正文哈希 => token 树（PT-17，保存即换 key 自然失效） */
    private static array $compiled = [];

    /** mm => px（96dpi，浏览器打印 CSS 基准） */
    public const MM_PX = 3.7795275591;

    /** 常用纸张规格（mm，portrait）。 */
    public const PAPER_SIZES = [
        'A4'     => ['w' => 210, 'h' => 297],
        'A5'     => ['w' => 148, 'h' => 210],
        'Letter' => ['w' => 216, 'h' => 279],
    ];

    /** 业务数据边界：仅 JSON 可表达结构（PT-16）。含资源/闭包即拒绝。 */
    public static function validateData(array $data): void
    {
        self::assertJsonable($data, '$');
    }

    /** 编译模板正文为 token 树（带进程内缓存）。 */
    public static function compile(string $source): array
    {
        $key = hash('sha256', $source);
        if (isset(self::$compiled[$key])) {
            return self::$compiled[$key];
        }
        $pos = 0;
        $tree = self::parse($source, $pos, null);
        if (count(self::$compiled) > 64) {
            self::$compiled = [];
        }
        self::$compiled[$key] = $tree;
        return $tree;
    }

    /** 渲染正文：先展开表格组件，再展开布局组件，最后填充占位符并解析附件图片引用。 */
    public static function renderBody(string $source, array $data): string
    {
        self::validateData($data);
        $html = self::expandTables($source, $data);
        $html = self::expandBlocks($html, $data);
        return self::resolveAttachSrcs(self::renderTokens(self::compile($html), $data, []));
    }

    /**
     * 解析正文中的附件图片引用（PT-19）：attach://<token> → /attachment/download?token=…&image=1。
     *
     * 模板保存时图片地址以 attach://<token> 形式固化（与运行环境解耦），渲染时才解析为附件
     * 下载地址；image=1 声明图片场景，token 失效/无元数据时由附件服务回退默认图片，避免破图。
     * 在占位符渲染之后执行，故 <img src="{{order.logo}}">（数据携带 attach:// 值）同样生效。
     */
    private static function resolveAttachSrcs(string $html): string
    {
        return (string) preg_replace_callback(
            '/src="(attach:\/\/[^"]+)"/i',
            static function (array $m): string {
                $token = substr($m[1], strlen('attach://'));
                return 'src="/attachment/download?token=' . rawurlencode($token) . '&amp;image=1"';
            },
            $html
        );
    }

    /**
     * 组装完整可打印 HTML 文档：@page 纸张/边距 + 版面容器 + 内嵌分页脚本（PT-8/PT-15/NFR-3/NFR-4）。
     *
     * paper: {size, w, h, orientation, margin:{t,r,b,l}}（mm；custom 用 w/h）
     */
    public static function pageDocument(string $bodyHtml, array $paper, string $title = ''): string
    {
        $paper = self::normalizePaper($paper);
        $m = $paper['margin'];
        $paperJson = json_encode($paper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $title = htmlspecialchars($title !== '' ? $title : '单据打印', ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html>' . "\n"
            . '<html lang="zh-CN"><head><meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . $title . '</title>' . "\n"
            . '<style>' . "\n"
            . '@page { size: ' . $paper['w'] . 'mm ' . $paper['h'] . 'mm; margin: 0; }' . "\n"
            . '* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' . "\n"
            . 'html, body { margin: 0; padding: 0; background: #606266; }' . "\n"
            . 'body { font-family: "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", Arial, sans-serif; color: #1f2329; }' . "\n"
            . '#pt-doc { display: none; }' . "\n"
            . '.pt-sheet { position: relative; overflow: hidden; background: #fff; margin: 0 auto 12px; }' . "\n"
            . '.pt-el { position: absolute; overflow: hidden; }' . "\n"
            . '.pt-el img { width: 100%; height: 100%; object-fit: contain; display: block; }' . "\n"
            . '.pt-kv { width: 100%; border-collapse: collapse; }' . "\n"
            . '.pt-kv td { padding: 2px 8px 2px 0; font-size: 12px; vertical-align: top; word-break: break-all; }' . "\n"
            . '.pt-kv .pt-kv-l { color: #606266; }' . "\n"
            . '.pt-kv .pt-kv-l:empty { display: none; }' . "\n"
            . '.pt-kv .pt-kv-l::after { content: "："; }' . "\n"
            . '.pt-cols { display: flex; gap: 6px; width: 100%; height: 100%; }' . "\n"
            . '.pt-col { flex: 1 1 0; min-width: 0; overflow: hidden; font-size: 12px; }' . "\n"
            . '.pt-col[style] { flex: 0 0 auto; }' . "\n"
            . '.pt-group { width: 100%; height: 100%; border: 1px solid #303133; }' . "\n"
            . '.pt-group-title { padding: 2px 8px; font-size: 12px; font-weight: 600; background: #fafafa; border-bottom: 1px solid #303133; }' . "\n"
            . '.pt-code { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; overflow: hidden; }' . "\n"
            . '.pt-code svg { max-width: 100%; max-height: 100%; }' . "\n"
            . '.pt-code .pt-code-tip { font-size: 11px; color: #f56c6c; }' . "\n"
            . '.pt-table { width: 100%; border-collapse: collapse; }' . "\n"
            . '.pt-table th, .pt-table td { border: 1px solid #303133; padding: 4px 6px; font-size: 12px; word-break: break-all; }' . "\n"
            . '.pt-table thead th { background: #f2f3f5; font-weight: 600; }' . "\n"
            . '.pt-table tr.pt-subtotal td { background: #fafafa; font-weight: 600; }' . "\n"
            . '.pt-table td.pt-empty { text-align: center; color: #909399; padding: 12px 0; }' . "\n"
            . '.pt-toolbar { position: fixed; top: 12px; right: 16px; display: flex; gap: 8px; z-index: 99; }' . "\n"
            . '.pt-toolbar button { padding: 8px 18px; border: 1px solid #dcdfe6; border-radius: 4px; background: #fff; cursor: pointer; font-size: 13px; }' . "\n"
            . '.pt-toolbar button.primary { background: #409eff; border-color: #409eff; color: #fff; }' . "\n"
            . '@media print { .pt-toolbar { display: none; } .pt-sheet { margin: 0; page-break-after: always; } .pt-sheet:last-child { page-break-after: auto; } body { background: #fff; } }' . "\n"
            . '</style>' . "\n"
            . '</head><body>' . "\n"
            . '<div class="pt-toolbar"><button type="button" onclick="window.print()">打 印</button>'
            . '<button type="button" onclick="window.close()">关 闭</button></div>' . "\n"
            . '<div id="pt-doc">' . $bodyHtml . '</div>' . "\n"
            . '<div id="pt-pages"></div>' . "\n"
            . '<script src="/static/vendor/qrcode/qrcode.js"></script>' . "\n"
            . '<script src="/static/vendor/barcode/JsBarcode.all.min.js"></script>' . "\n"
            . '<script>__PT_PAPER__ = ' . $paperJson . ';</script>' . "\n"
            . '<script>' . self::paginationJs() . '</script>' . "\n"
            . '</body></html>';
    }

    /** 纸张配置归一：缺省 A4 竖版、10mm 边距；尺寸/边距限定合理范围。 */
    public static function normalizePaper(array $paper): array
    {
        $size = (string) ($paper['size'] ?? 'A4');
        if (!isset(self::PAPER_SIZES[$size])) {
            $size = 'custom';
        }
        if ($size === 'custom') {
            $w = max(30.0, min(2000.0, (float) ($paper['w'] ?? 210)));
            $h = max(30.0, min(2000.0, (float) ($paper['h'] ?? 297)));
        } else {
            $w = self::PAPER_SIZES[$size]['w'];
            $h = self::PAPER_SIZES[$size]['h'];
        }
        if (($paper['orientation'] ?? 'portrait') === 'landscape') {
            [$w, $h] = [$h, $w];
        }
        $mg = is_array($paper['margin'] ?? null) ? $paper['margin'] : [];
        $clampMm = static function ($v): float {
            return max(0.0, min(60.0, (float) $v));
        };
        return [
            'size'        => $size,
            'w'           => $w,
            'h'           => $h,
            'orientation' => ($paper['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
            'margin'      => [
                't' => $clampMm($mg['t'] ?? 10),
                'r' => $clampMm($mg['r'] ?? 10),
                'b' => $clampMm($mg['b'] ?? 10),
                'l' => $clampMm($mg['l'] ?? 10),
            ],
        ];
    }

    // ---------------------------------------------------------------- 解析

    /**
     * 递归下降解析。$stop 传 'each'/'if' 时遇到对应闭合标签返回。
     * token: ['t', text] | ['v', path, escaped] | ['e', path, body] | ['i', path, body, elseBody]
     */
    private static function parse(string $s, int &$pos, ?string $stop): array
    {
        $tokens = [];
        $len = strlen($s);
        $buf = '';
        $flush = static function () use (&$buf, &$tokens): void {
            if ($buf !== '') {
                $tokens[] = ['t', $buf];
                $buf = '';
            }
        };
        while ($pos < $len) {
            $open = strpos($s, '{{', $pos);
            if ($open === false || $open > $pos) {
                $end = $open === false ? $len : $open;
                $buf .= substr($s, $pos, $end - $pos);
                $pos = $end;
            }
            if ($open === false) {
                break;
            }
            // 三括号不转义输出
            if (substr($s, $open, 3) === '{{{') {
                $close = strpos($s, '}}}', $open + 3);
                if ($close === false) {
                    throw new \RuntimeException('占位符语法错误：{{{ 缺少闭合 }}}（位置 ' . $open . '）');
                }
                $path = trim(substr($s, $open + 3, $close - $open - 3));
                if ($path === '' || preg_match('/[{}\s]/', $path)) {
                    throw new \RuntimeException('占位符语法错误：{{{ }}} 内需为合法点路径（位置 ' . $open . '）');
                }
                $flush();
                $tokens[] = ['v', $path, false];
                $pos = $close + 3;
                continue;
            }
            $close = strpos($s, '}}', $open + 2);
            if ($close === false) {
                throw new \RuntimeException('占位符语法错误：{{ 缺少闭合 }}（位置 ' . $open . '）');
            }
            $inner = trim(substr($s, $open + 2, $close - $open - 2));
            $pos = $close + 2;
            if ($inner === '') {
                throw new \RuntimeException('占位符语法错误：空占位符（位置 ' . $open . '）');
            }
            if ($inner === '#each' || str_starts_with($inner, '#each ')) {
                $flush();
                $path = trim(substr($inner, 5));
                if ($path === '' || preg_match('/[{}\s]/', $path)) {
                    throw new \RuntimeException('占位符语法错误：{{#each}} 后需为合法点路径（位置 ' . $open . '）');
                }
                $body = self::parse($s, $pos, 'each');
                $tokens[] = ['e', $path, $body];
                continue;
            }
            if ($inner === '/each') {
                if ($stop !== 'each') {
                    throw new \RuntimeException('占位符语法错误：多余的 {{/each}}（位置 ' . $open . '）');
                }
                $flush();
                return $tokens;
            }
            if ($inner === '#if' || str_starts_with($inner, '#if ')) {
                if ($stop !== null) {
                    throw new \RuntimeException('占位符语法错误：if 块不可嵌套于 each/if 内（v1 限制，位置 ' . $open . '）');
                }
                $flush();
                $path = trim(substr($inner, 3));
                if ($path === '' || preg_match('/[{}\s]/', $path)) {
                    throw new \RuntimeException('占位符语法错误：{{#if}} 后需为合法点路径（位置 ' . $open . '）');
                }
                self::$sawElse = false;
                $body = self::parse($s, $pos, 'if');
                // parse 到 {{else}} 时返回：继续解析 else 分支直到 {{/if}}
                $elseBody = [];
                if (self::$sawElse) {
                    self::$sawElse = false;
                    $elseBody = self::parse($s, $pos, 'if');
                }
                $tokens[] = ['i', $path, $body, $elseBody];
                continue;
            }
            if ($inner === 'else') {
                if ($stop !== 'if') {
                    throw new \RuntimeException('占位符语法错误：{{else}} 必须位于 {{#if}} 块内（位置 ' . $open . '）');
                }
                $flush();
                self::$sawElse = true;
                return $tokens;
            }
            if ($inner === '/if') {
                if ($stop !== 'if') {
                    throw new \RuntimeException('占位符语法错误：多余的 {{/if}}（位置 ' . $open . '）');
                }
                $flush();
                return $tokens;
            }
            if (str_starts_with($inner, '#') || str_starts_with($inner, '/')) {
                throw new \RuntimeException('占位符语法错误：未知块标签 {{' . $inner . '}}（v1 支持 each/if，位置 ' . $open . '）');
            }
            // 格式化占位符（T-4）：{{date 路径 '格式'}} / {{money 路径 小数位}}
            if (preg_match('/^(date|money)\s+([^\s{}]+)(?:\s+(.+))?$/', $inner, $fm)) {
                $arg = trim($fm[3] ?? '');
                if ($fm[1] === 'money' && $arg !== '' && !preg_match('/^\d+$/', $arg)) {
                    throw new \RuntimeException('占位符语法错误：{{money}} 小数位参数需为数字，收到 ' . $arg . '（位置 ' . $open . '）');
                }
                $flush();
                $tokens[] = ['f', $fm[1], $fm[2], $arg, true];
                continue;
            }
            if (preg_match('/[{}\s]/', $inner)) {
                throw new \RuntimeException('占位符语法错误：{{ }} 内需为合法点路径（位置 ' . $open . '）');
            }
            $flush();
            $tokens[] = ['v', $inner, true];
        }
        if ($stop !== null) {
            throw new \RuntimeException('占位符语法错误：{{#' . $stop . '}} 未闭合（缺少 {{/' . $stop . '}}）');
        }
        $flush();
        return $tokens;
    }

    // ---------------------------------------------------------------- 渲染

    /** 渲染 token 树。stack：each 循环上下文（从外到内），元素 [item, index]。 */
    private static function renderTokens(array $tokens, array $root, array $stack): string
    {
        $out = '';
        foreach ($tokens as $tk) {
            switch ($tk[0]) {
                case 't':
                    $out .= $tk[1];
                    break;
                case 'v':
                    $out .= self::renderVar($tk[1], $tk[2], $root, $stack);
                    break;
                case 'f':
                    $out .= self::renderFormatted($tk[1], $tk[2], $tk[3], $root, $stack);
                    break;
                case 'e':
                    $items = self::lookup($tk[1], $root, $stack);
                    if (!is_array($items)) {
                        break;
                    }
                    $idx = 0;
                    foreach ($items as $item) {
                        $stack[] = [$item, $idx];
                        $out .= self::renderTokens($tk[2], $root, $stack);
                        array_pop($stack);
                        $idx++;
                    }
                    break;
                case 'i':
                    if (self::truthy(self::lookup($tk[1], $root, $stack))) {
                        $out .= self::renderTokens($tk[2], $root, $stack);
                    } else {
                        $out .= self::renderTokens($tk[3], $root, $stack);
                    }
                    break;
            }
        }
        return $out;
    }

    /** 变量输出；路径首段 pt（pt.page/pt.pages）保留字面交由打印页脚本替换。 */
    private static function renderVar(string $path, bool $escaped, array $root, array $stack): string
    {
        if ($path === 'pt.page' || $path === 'pt.pages') {
            return '{{' . $path . '}}';
        }
        $v = self::lookup($path, $root, $stack);
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if ($v === null || $v === false) {
            $v = '';
        }
        $s = (string) $v;
        return $escaped ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : $s;
    }

    /**
     * 格式化占位符输出（T-4）：date 日期格式化 / money 金额千分位。
     * 取值缺失输出空串（与占位符容忍语义一致）；无法解析/非数字时输出原文，不静默吞数据。
     * date：优先 strtotime（覆盖 'Y-m-d H:i:s' 等常见串），失败且为 10 位纯数字按秒级时间戳。
     * money：number_format 千分位，小数位 0-4（编译期已校验为数字，此处钳位）。
     */
    private static function renderFormatted(string $fn, string $path, string $arg, array $root, array $stack): string
    {
        $v = self::lookup($path, $root, $stack);
        if ($v === null || $v === false) {
            return '';
        }
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $s = (string) $v;
        if ($s === '') {
            return '';
        }
        if ($fn === 'date') {
            $fmt = $arg !== '' ? trim($arg, '\'"') : 'Y-m-d';
            $ts = strtotime($s);
            if ($ts === false && preg_match('/^\d{10}$/', $s)) {
                $ts = (int) $s;
            }
            $out = $ts !== false ? date($fmt, $ts) : $s;
        } else {
            $dec = $arg !== '' ? max(0, min(4, (int) $arg)) : 2;
            $out = is_numeric($s) ? number_format((float) $s, $dec, '.', ',') : $s;
        }
        return htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 点路径取值：从最内层 each 上下文向外查找首段（Handlebars 语义），
     * '@index' 取当前循环序号；取不到返回 null（输出空串）。
     */
    private static function lookup(string $path, array $root, array $stack): mixed
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            [$item, $idx] = $stack[$i];
            if ($path === '@index') {
                return $idx;
            }
            $head = strstr($path, '.', true) ?: $path;
            if (is_array($item) && array_key_exists($head, $item)) {
                return self::dig($item, $path);
            }
        }
        return self::dig($root, $path);
    }

    /** 逐段下钻点路径。 */
    private static function dig(array $ctx, string $path): mixed
    {
        $cur = $ctx;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /** 真值判定（if 块）。 */
    private static function truthy(mixed $v): bool
    {
        if ($v === null || $v === false) {
            return false;
        }
        if ($v === true) {
            return true;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        if (is_string($v)) {
            return $v !== '';
        }
        if (is_array($v)) {
            return $v !== [];
        }
        return (bool) $v;
    }

    /** JSON 可表达结构校验（PT-16）：资源/闭包拒绝。 */
    private static function assertJsonable(mixed $v, string $at): void
    {
        if (is_resource($v)) {
            throw new PrintTplQuota('业务数据含非法结构（resource）@ ' . $at);
        }
        if ($v instanceof \Closure) {
            throw new PrintTplQuota('业务数据含非法结构（Closure）@ ' . $at);
        }
        if (is_array($v)) {
            foreach ($v as $k => $sub) {
                self::assertJsonable($sub, $at . '.' . $k);
            }
        }
    }

    // ---------------------------------------------------------------- 表格组件

    /**
     * 展开正文中的表格组件：按 data-cols 生成 thead/tbody（分组小计 + 组内合并）。
     * 每内层 each 上下文 v1 不进入表格（表格组件置于顶层）。
     */
    public static function expandTables(string $html, array $data): string
    {
        return preg_replace_callback(
            '/<div class="pt-el" data-pt="table"([^>]*)>\s*<\/div>/',
            static function (array $m) use ($data): string {
                $attrs = self::parseAttrs($m[1]);
                return self::renderTable($attrs, $data);
            },
            $html
        );
    }

    // ---------------------------------------------------------------- 布局组件

    /**
     * 展开布局组件（PT-20 / PT-21，均为自绘组件、不嵌套子组件模型）：
     *  - kv：键值对网格（单据头部信息区），值生成 {{路径}} 占位符交由占位符渲染统一填充；
     *  - cols：分栏并排内容（栏内容为可信源 HTML，可含占位符）；
     *  - group：带标题条的边框容器（其他组件绝对定位叠放其上）；
     *  - code：二维码 / 条码（T-6 v1.1），输出 .pt-code 空容器（data-kind/data-value），
     *    value 中占位符交由外层占位符渲染填充，打印页脚本/编辑器画布按 kind 生成 SVG 图形。
     * 编辑器产物约定同表格：组件 div 恒为空体，配置存 data-* 属性。
     */
    public static function expandBlocks(string $html, array $data): string
    {
        return (string) preg_replace_callback(
            '/<div class="pt-el" data-pt="(kv|cols|group|code)"([^>]*)>\s*<\/div>/',
            static function (array $m) use ($data): string {
                $attrs = self::parseAttrs($m[2]);
                return match ($m[1]) {
                    'kv'   => self::renderKv($attrs),
                    'cols' => self::renderCols($attrs),
                    'code' => self::renderCode($attrs),
                    default => self::renderGroup($attrs),
                };
            },
            $html
        );
    }

    /**
     * 键值对组件：data-cols（1-4 列，默认 2）+ data-items（JSON [{l:标签, p:值点路径}]）。
     * 值路径不存在时容忍缺失：标签仍在、值输出空串（占位符渲染语义）。
     */
    public static function renderKv(array $attrs): string
    {
        $n = max(1, min(4, (int) ($attrs['data-cols'] ?? 2)));
        $items = json_decode((string) ($attrs['data-items'] ?? '[]'), true);
        if (!is_array($items) || $items === []) {
            return '<div class="pt-el" data-pt="kv"><table class="pt-kv"><tbody><tr><td class="pt-empty">键值对组件未配置条目</td></tr></tbody></table></div>';
        }
        $html = '<div class="pt-el" data-pt="kv"><table class="pt-kv"><tbody>';
        foreach (array_chunk($items, $n) as $row) {
            $html .= '<tr>';
            for ($i = 0; $i < $n; $i++) {
                $it = $row[$i] ?? null;
                if (!is_array($it)) {
                    $html .= '<td></td>';
                    continue;
                }
                $label = htmlspecialchars((string) ($it['l'] ?? ''), ENT_QUOTES, 'UTF-8');
                $path = trim((string) ($it['p'] ?? ''));
                $html .= '<td><span class="pt-kv-l">' . $label . '</span>' . ($path !== '' ? '{{' . $path . '}}' : '') . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** 分栏组件：data-items（JSON [{w:栏宽（数字+%|px|mm，空则均分）, html:栏内容（可信源，可含占位符）}]）。 */
    public static function renderCols(array $attrs): string
    {
        $items = json_decode((string) ($attrs['data-items'] ?? '[]'), true);
        if (!is_array($items) || $items === []) {
            return '<div class="pt-el" data-pt="cols"><div class="pt-cols"><div class="pt-col">分栏组件未配置栏</div></div></div>';
        }
        $html = '<div class="pt-el" data-pt="cols"><div class="pt-cols">';
        foreach ($items as $it) {
            $html .= '<div class="pt-col"';
            if (preg_match('/^\d+(\.\d+)?(%|px|mm)$/', (string) ($it['w'] ?? ''))) {
                $html .= ' style="width:' . htmlspecialchars((string) $it['w'], ENT_QUOTES, 'UTF-8') . '"';
            }
            $html .= '>' . (string) ($it['html'] ?? '') . '</div>';
        }
        return $html . '</div></div>';
    }

    /** 分组框组件：data-title（标题条文案，空则只画边框）；框内区域供其他组件绝对定位叠放。 */
    public static function renderGroup(array $attrs): string
    {
        $title = htmlspecialchars((string) ($attrs['data-title'] ?? ''), ENT_QUOTES, 'UTF-8');
        return '<div class="pt-el" data-pt="group"><div class="pt-group">'
            . ($title !== '' ? '<div class="pt-group-title">' . $title . '</div>' : '')
            . '</div></div>';
    }

    /**
     * 二维码 / 条码组件（T-6 v1.1）：data-kind（qr 二维码 / bar 条码 Code128）+ data-value（内容，可含占位符）。
     * value 中的 {{路径}} 保留原文，由外层占位符渲染填充；图形由打印页脚本 / 编辑器画布按 kind 生成 SVG。
     */
    public static function renderCode(array $attrs): string
    {
        $kind = (($attrs['data-kind'] ?? '') === 'bar') ? 'bar' : 'qr';
        $val = (string) ($attrs['data-value'] ?? '');
        return '<div class="pt-el" data-pt="code"><div class="pt-code" data-kind="' . $kind
            . '" data-value="' . self::attr($val) . '"></div></div>';
    }

    /** 解析 HTML 标签属性串（值经 htmlspecialchars 编码存储，此处解码；兼容单/双引号属性）。 */
    public static function parseAttrs(string $attrStr): array
    {
        $attrs = [];
        if (preg_match_all('/([a-zA-Z0-9_-]+)=([\'"])(.*?)\2/', $attrStr, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $attrs[strtolower($m[1])] = html_entity_decode($m[3], ENT_QUOTES, 'UTF-8');
            }
        }
        return $attrs;
    }

    /** 属性值编码（存储侧统一走此函数，与 parseAttrs 对偶）。 */
    public static function attr(string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 生成表格 HTML。
     * attrs: data-bind(数组点路径) / data-cols(JSON 列定义) / data-group / data-merge / data-empty
     */
    public static function renderTable(array $attrs, array $data, array $stack = []): string
    {
        $rows = self::lookup((string) ($attrs['data-bind'] ?? ''), $data, $stack);
        if (!is_array($rows)) {
            $rows = [];
        }
        // 保持键名列表化（关联数组按值序渲染）
        $rows = array_values(array_filter($rows, 'is_array'));
        $cols = json_decode((string) ($attrs['data-cols'] ?? '[]'), true);
        if (!is_array($cols) || $cols === []) {
            return '<div class="pt-el" data-pt="table"><table class="pt-table"><tbody><tr><td class="pt-empty">表格组件未配置列</td></tr></tbody></table></div>';
        }
        $group = (string) ($attrs['data-group'] ?? '');
        $merge = (string) ($attrs['data-merge'] ?? '');
        $empty = (string) ($attrs['data-empty'] ?? '暂无数据');

        $thead = '<thead><tr>';
        foreach ($cols as $c) {
            $style = ' style="' . self::colStyle($c) . '"';
            $thead .= '<th' . $style . '>' . htmlspecialchars((string) ($c['t'] ?? ''), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $thead .= '</tr></thead>';

        if ($rows === []) {
            $n = count($cols);
            return '<div class="pt-el" data-pt="table"><table class="pt-table">' . $thead
                . '<tbody><tr><td class="pt-empty" colspan="' . $n . '">' . htmlspecialchars($empty, ENT_QUOTES, 'UTF-8') . '</td></tr></tbody></table></div>';
        }

        // 分段：分组小计 or 整体一段；段内再做合并
        $segments = $group !== '' ? self::splitGroups($rows, $group) : [[$rows, null]];
        $tbody = '<tbody>';
        foreach ($segments as [$segRows, $groupVal]) {
            $tbody .= self::renderRows($segRows, $cols, $merge);
            if ($groupVal !== null) {
                $tbody .= self::subtotalRow($segRows, $cols, $groupVal);
            }
        }
        $tbody .= '</tbody>';

        return '<div class="pt-el" data-pt="table"><table class="pt-table">' . $thead . $tbody . '</table></div>';
    }

    /** 列头/单元格样式。 */
    private static function colStyle(array $c): string
    {
        $css = '';
        if (isset($c['w']) && (string) $c['w'] !== '') {
            $css .= 'width:' . self::attr((string) $c['w']) . ';';
        }
        $align = (string) ($c['align'] ?? '');
        if (in_array($align, ['left', 'center', 'right'], true)) {
            $css .= 'text-align:' . $align . ';';
        }
        return $css;
    }

    /** 按分组列把行切段（保持出现顺序）。 */
    private static function splitGroups(array $rows, string $group): array
    {
        $segments = [];
        $order = [];
        foreach ($rows as $r) {
            $val = self::cellStr($r, $group);
            if (!isset($segments[$val])) {
                $segments[$val] = [];
                $order[] = $val;
            }
            $segments[$val][] = $r;
        }
        $out = [];
        foreach ($order as $val) {
            $out[] = [$segments[$val], $val];
        }
        return $out;
    }

    /** 渲染一段行（含组内相邻同值合并）。 */
    private static function renderRows(array $rows, array $cols, string $merge): string
    {
        // 合并预扫描：merge 列连续同值段（仅在段内）
        $spans = $merge !== '' ? self::mergeSpans($rows, $cols, $merge) : [];
        $html = '';
        foreach ($rows as $ri => $r) {
            $html .= '<tr>';
            foreach ($cols as $ci => $c) {
                $k = (string) ($c['k'] ?? '');
                if (isset($spans[$ci])) {
                    if ($spans[$ci]['skip'][$ri]) {
                        continue; // 段内非首行：不输出该 td
                    }
                    $rowspan = $spans[$ci]['span'][$ri];
                    $html .= '<td' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '')
                        . ' style="' . self::colStyle($c) . '">' . self::cellHtml($r, $k) . '</td>';
                    continue;
                }
                $html .= '<td style="' . self::colStyle($c) . '">' . self::cellHtml($r, $k) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html;
    }

    /** 计算各合并列的 rowspan/跳过矩阵。返回 [colIndex => ['span'=>[], 'skip'=>[]]]。 */
    private static function mergeSpans(array $rows, array $cols, string $merge): array
    {
        $spans = [];
        foreach ($cols as $ci => $c) {
            if ((string) ($c['k'] ?? '') !== $merge) {
                continue;
            }
            $n = count($rows);
            $span = array_fill(0, $n, 1);
            $skip = array_fill(0, $n, false);
            $i = 0;
            while ($i < $n) {
                $j = $i + 1;
                $base = self::cellStr($rows[$i], $merge);
                while ($j < $n && self::cellStr($rows[$j], $merge) === $base) {
                    $skip[$j] = true;
                    $span[$i]++;
                    $j++;
                }
                $i = $j;
            }
            $spans[$ci] = ['span' => $span, 'skip' => $skip];
        }
        return $spans;
    }

    /** 分组小计行：第一个非小计列显示「{组值} 小计」，subtotal 列求和（两位小数）。 */
    private static function subtotalRow(array $rows, array $cols, string $groupVal): string
    {
        $cells = [];
        $firstPlain = true;
        foreach ($cols as $c) {
            if (!empty($c['subtotal'])) {
                $sum = 0.0;
                $hasNum = false;
                foreach ($rows as $r) {
                    $v = self::cellStr($r, (string) ($c['k'] ?? ''));
                    if (is_numeric($v)) {
                        $sum += (float) $v;
                        $hasNum = true;
                    }
                }
                $cells[] = '<td style="' . self::colStyle($c) . '">' . ($hasNum ? self::num2($sum) : '-') . '</td>';
            } else {
                $cells[] = '<td style="' . self::colStyle($c) . '">'
                    . ($firstPlain ? htmlspecialchars($groupVal . ' 小计', ENT_QUOTES, 'UTF-8') : '&nbsp;') . '</td>';
                $firstPlain = false;
            }
        }
        return '<tr class="pt-subtotal">' . implode('', $cells) . '</tr>';
    }

    /** 单元格取值转义输出。 */
    private static function cellHtml(array $row, string $k): string
    {
        $v = $k === '' ? '' : self::dig($row, $k);
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if ($v === null || $v === false) {
            $v = '';
        }
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** 单元格字符串化（分组/合并比较与小计求和用）。 */
    private static function cellStr(array $row, string $k): string
    {
        $v = $k === '' ? '' : self::dig($row, $k);
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if ($v === null || $v === false) {
            return '';
        }
        return (string) $v;
    }

    /** 金额/数量固定两位小数（打印单据金额格式）。 */
    private static function num2(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    // ---------------------------------------------------------------- 打印页分页脚本

    /**
     * 打印页客户端分页脚本（PT-8：明细表跨页表头重复、页眉页脚每页、{{pt.page}}/{{pt.pages}} 页码）。
     *
     * 布局模型：#pt-doc 内 .pt-el 组件（绝对定位画布）→ 按页切片输出到 #pt-pages：
     *  - data-fixed="header|footer"：页眉/页脚组件，每页重复（页 1 仍按原坐标）；
     *  - 第一个 data-pt="table"：主明细表，行逐页切片；每页生成独立 <table>（克隆 thead，列宽一致）；
     *  - 主表格之后的静态组件：跟随末页，在表格结束后按文档流顺序堆叠；
     *  - 其余静态组件：跟随第 1 页原坐标。
     */
    private static function paginationJs(): string
    {
        return <<<'JS'
(function () {
  'use strict';
  var MM = 3.7795275591;
  var P = window.__PT_PAPER__ || {};
  var doc = document.getElementById('pt-doc');
  var pages = document.getElementById('pt-pages');
  if (!doc || !pages) { return; }
  var W = (P.w || 210) * MM, H = (P.h || 297) * MM;
  var MT = (P.margin && P.margin.t || 10) * MM, MR = (P.margin && P.margin.r || 10) * MM;
  var MB = (P.margin && P.margin.b || 10) * MM, ML = (P.margin && P.margin.l || 10) * MM;
  var iw = Math.round(W - ML - MR), ih = Math.round(H - MT - MB);

  function sheetDOM(pageNo) {
    var s = document.createElement('div');
    s.className = 'pt-sheet';
    s.style.width = Math.round(W) + 'px';
    s.style.height = Math.round(H) + 'px';
    s.style.paddingTop = Math.round(MT) + 'px';
    s.style.paddingRight = Math.round(MR) + 'px';
    s.style.paddingBottom = Math.round(MB) + 'px';
    s.style.paddingLeft = Math.round(ML) + 'px';
    s.setAttribute('data-page', String(pageNo));
    var b = document.createElement('div');
    b.className = 'pt-pbody';
    b.style.position = 'relative';
    b.style.width = iw + 'px';
    b.style.height = ih + 'px';
    b.style.overflow = 'hidden';
    s.appendChild(b);
    return s;
  }

  // 二维码/条码图形生成（T-6 v1.1）：分页前把 .pt-code 画为 SVG，分页克隆副本自动携带。
  // data-drawn 记录已绘制的 "kind|value"，类型或内容变化时自动重绘（编辑器画布共用同一逻辑）。
  function drawCodeTip(el, msg) {
    el.innerHTML = '<span class="pt-code-tip">' + msg + '</span>';
    el.setAttribute('data-drawn', (el.getAttribute('data-kind') || 'qr') + '|' + (el.getAttribute('data-value') || ''));
  }
  function drawCodes(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.pt-code'), function (el) {
      var kind = el.getAttribute('data-kind');
      var val = el.getAttribute('data-value') || '';
      var mark = kind + '|' + val;
      if (el.getAttribute('data-drawn') === mark) { return; }
      if (!val) { drawCodeTip(el, '二维码/条码内容为空'); return; }
      try {
        if (typeof qrcode === 'undefined' || typeof JsBarcode === 'undefined') {
          drawCodeTip(el, '图形库未加载'); return;
        }
        if (kind === 'bar') {
          var ph = 0, p = el.closest ? el.closest('.pt-el') : null;
          if (p && p.style.height) { ph = parseFloat(p.style.height) || 0; }
          var bh = ph > 0 ? Math.max(24, Math.min(120, ph - 18)) : 50;
          var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          JsBarcode(svg, val, { format: 'CODE128', displayValue: true, fontSize: 12, margin: 4, height: bh, width: 2 });
          el.innerHTML = '';
          el.appendChild(svg);
        } else {
          if (!qrcode.stringToBytes || !qrcode.stringToBytes.__utf8) {
            qrcode.stringToBytes = function (s) {
              var u = unescape(encodeURIComponent(s)), b = [];
              for (var i = 0; i < u.length; i++) { b.push(u.charCodeAt(i) & 0xff); }
              return b;
            };
            qrcode.stringToBytes.__utf8 = true;
          }
          var qr = qrcode(0, 'M');
          qr.addData(val);
          qr.make();
          el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
          var qs = el.querySelector('svg');
          if (qs) { qs.style.width = '100%'; qs.style.height = '100%'; }
        }
        el.setAttribute('data-drawn', mark);
      } catch (e) {
        drawCodeTip(el, '内容无法生成图形');
      }
    });
  }
  drawCodes(doc);

  var els = Array.prototype.slice.call(doc.querySelectorAll('.pt-el'));
  var headers = [], footers = [], statics = [], tableEl = null;
  els.forEach(function (el) {
    var fixed = el.getAttribute('data-fixed');
    if (fixed === 'header') { headers.push(el); return; }
    if (fixed === 'footer') { footers.push(el); return; }
    if (el.getAttribute('data-pt') === 'table' && !tableEl) { tableEl = el; return; }
    statics.push(el);
  });
  // 表格前/后静态组件（以表格 top 为界；无表格时全部算前置）
  var tableTop = tableEl ? (parseFloat(tableEl.style.top) || 0) : ih;
  var pre = [], post = [];
  statics.forEach(function (el) {
    var top = parseFloat(el.style.top) || 0;
    if (!tableEl || top < tableTop) { pre.push(el); } else { post.push(el); }
  });
  post.sort(function (a, b) { return (parseFloat(a.style.top) || 0) - (parseFloat(b.style.top) || 0); });

  function clone(el) { return el.cloneNode(true); }
  function place(container, el) { container.appendChild(clone(el)); }

  var sheets = [], bodies = [];
  var pageIdx = 0;
  function newPage() {
    pageIdx++;
    var s = sheetDOM(pageIdx);
    pages.appendChild(s);
    sheets.push(s);
    bodies.push(s.querySelector('.pt-pbody'));
    headers.forEach(function (h) { place(bodies[bodies.length - 1], h); });
    footers.forEach(function (f) { place(bodies[bodies.length - 1], f); });
    return bodies[bodies.length - 1];
  }
  function stampFixed(page, total) {
    bodies.forEach(function (b, i) {
      var fs = b.querySelectorAll('.pt-el[data-fixed="footer"]');
      Array.prototype.forEach.call(fs, function (el) {
        el.innerHTML = el.innerHTML.replace(/\{\{pt\.page\}\}/g, String(i + 1)).replace(/\{\{pt\.pages\}\}/g, String(total));
      });
    });
  }

  // 页 1：前置静态组件按原坐标（含页眉页脚原位）
  var body0 = newPage();
  pre.forEach(function (el) { place(body0, el); });

  // 页底界限：页脚最高点 top（无页脚则为整页高）
  var footerLimit = ih;
  footers.forEach(function (f) {
    var ft = parseFloat(f.style.top);
    if (!isNaN(ft) && ft > 0 && ft < footerLimit) { footerLimit = ft; }
  });
  // 后续页表格起始：页眉最低点 bottom（无页眉为 0）
  var headerBottom = 0;
  headers.forEach(function (h) {
    var t = parseFloat(h.style.top) || 0;
    var hh = parseFloat(h.style.height) || 0;
    headerBottom = Math.max(headerBottom, t + hh);
  });

  function measure(el, width) {
    var c = clone(el);
    c.style.position = 'absolute';
    c.style.top = '-9999px';
    c.style.left = '0';
    if (width) { c.style.width = width + 'px'; }
    document.body.appendChild(c);
    var h = c.offsetHeight;
    document.body.removeChild(c);
    return h;
  }

  var totalPages = 1;
  if (tableEl) {
    var tbl = tableEl.querySelector('table');
    var thead = tbl ? tbl.querySelector('thead') : null;
    var tbody = tbl ? tbl.querySelector('tbody') : null;
    var rows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
    var tableLeft = parseFloat(tableEl.style.left) || 0;
    var tableW = parseFloat(tableEl.style.width) || iw;

    var queue = rows.slice();
    var bi = 0;                    // 当前页索引
    var tableTopY = tableTop;      // 当前页表格顶 Y
    var pageTable = null;
    function newTable(y) {
      var t = document.createElement('table');
      t.className = 'pt-table';
      t.style.position = 'absolute';
      t.style.left = tableLeft + 'px';
      t.style.top = y + 'px';
      t.style.width = tableW + 'px';
      if (thead) { t.appendChild(thead.cloneNode(true)); }
      t.appendChild(document.createElement('tbody'));
      bodies[bi].appendChild(t);
      return t;
    }
    pageTable = newTable(tableTopY);
    while (queue.length) {
      var tr = queue[0].cloneNode(true);
      pageTable.tBodies[0].appendChild(tr);
      var bottom = tableTopY + pageTable.offsetHeight;
      if (bottom > footerLimit && pageTable.tBodies[0].rows.length > 1) {
        // 本页装不下：回退该行，翻页重放（保证每页至少 1 行，防死循环）
        pageTable.tBodies[0].removeChild(tr);
        newPage();
        bi = bodies.length - 1;
        tableTopY = headerBottom;
        pageTable = newTable(tableTopY);
        pageTable.tBodies[0].appendChild(tr);
      }
      queue.shift();
    }
    // 末页：表格后静态组件在表格下方顺序堆叠
    var cursor = tableTopY + pageTable.offsetHeight + 12;
    post.forEach(function (el) {
      var c = clone(el);
      var h = measure(el, parseFloat(el.style.width) || iw);
      c.style.position = 'absolute';
      c.style.left = (parseFloat(el.style.left) || 0) + 'px';
      c.style.width = (parseFloat(el.style.width) || iw) + 'px';
      c.style.top = cursor + 'px';
      bodies[bi].appendChild(c);
      cursor += h + 6;
    });
    totalPages = bodies.length;
  } else {
    totalPages = bodies.length;
  }

  stampFixed(1, totalPages);
  if (doc.parentNode) { doc.parentNode.removeChild(doc); }
  var qs = new URLSearchParams(location.search);
  if (qs.get('autoprint') === '1') {
    setTimeout(function () { window.print(); }, 300);
  }
})();
JS;
    }
}
