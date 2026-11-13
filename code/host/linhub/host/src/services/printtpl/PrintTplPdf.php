<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

/**
 * 服务端 PDF 生成（T-3 后续项落地）—— 无头浏览器打印渲染文档为 PDF 文件。
 *
 * 方案：不走 HTTP 自访问（admin 应用全程登录保护，printTicket 票据页无法免登录打开），
 * 而是 ① 渲染得到完整打印文档 HTML；② 将其中 /static/vendor 前端库引用改写为 file:// 绝对地址；
 * ③ 写入系统临时目录；④ 调用无头 Edge/Chrome `--print-to-pdf` 输出 PDF（与浏览器打印同一引擎，
 * @page 纸张、内嵌分页脚本、条码 SVG 绘制全部生效，打印质量与浏览器打印一致）。
 *
 * 浏览器探测顺序：环境变量 PRINTTPL_PDF_BROWSER（指定可执行文件绝对路径）
 *   → Windows 常见安装路径（Edge / Chrome）→ Linux `command -v`（chromium 等）。
 * 找不到时抛 PrintTplDenied，提示安装浏览器或设置环境变量。
 *
 * 产物位于系统临时目录 printtpl_pdf/，约 10 分钟后由后续生成动作顺带清理；
 * 调用方（功能包）拿到绝对路径后自行下载、归档或发送。
 */
final class PrintTplPdf
{
    /** 渲染文档中会引用的前端库脚本（与 Renderer::pageDocument 输出一致）。 */
    private const VENDOR_ASSETS = [
        '/static/vendor/qrcode/qrcode.js',
        '/static/vendor/barcode/JsBarcode.all.min.js',
    ];

    /** 产物临时文件保留时长（秒），超期文件在下一次生成时清理。 */
    private const TMP_TTL = 600;

    /** 探测可用的无头浏览器可执行文件，找不到抛 PrintTplDenied。 */
    public static function resolveBrowser(): string
    {
        $env = getenv('PRINTTPL_PDF_BROWSER');
        if (is_string($env) && $env !== '') {
            if (is_file($env)) {
                return $env;
            }
            throw new PrintTplDenied('PRINTTPL_PDF_BROWSER 指定的浏览器不存在：' . $env);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            ];
            foreach ($candidates as $p) {
                if (is_file($p)) {
                    return $p;
                }
            }
        } else {
            foreach (['chromium-browser', 'chromium', 'google-chrome'] as $bin) {
                $p = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
                if ($p !== '') {
                    return $p;
                }
            }
        }
        throw new PrintTplDenied('未检测到可用的 Chrome/Edge 浏览器，无法服务端生成 PDF（可设环境变量 PRINTTPL_PDF_BROWSER 指定）');
    }

    /**
     * 将打印文档中 /static/vendor 前端库引用改写为 file:// 绝对地址。
     * 无头浏览器以 file:// 方式打开临时文档，根相对路径 /static/... 无法解析，必须改写。
     * vendor 目录定位失败抛 PrintTplDenied。
     */
    public static function localizeAssets(string $html, ?string $vendorDir = null): string
    {
        // 本类位于 host/src/services/printtpl/，上溯 5 级为平台根（code/host）
        $dir = $vendorDir ?? dirname(__DIR__, 5) . '/public/static/vendor';
        $real = realpath($dir);
        if ($real === false) {
            throw new PrintTplDenied('未找到静态资源目录 ' . $dir . '，无法生成 PDF');
        }
        $base = 'file:///' . str_replace('\\', '/', rtrim($real, '/\\'));
        foreach (self::VENDOR_ASSETS as $asset) {
            // /static/vendor/qrcode/qrcode.js → /qrcode/qrcode.js（base 已指向 vendor 目录）
            $rel = substr($asset, strlen('/static/vendor'));
            $html = str_replace('src="' . $asset . '"', 'src="' . $base . $rel . '"', $html);
        }
        return $html;
    }

    /**
     * 打印文档 HTML → PDF 文件。
     *
     * @param string $html    Renderer::pageDocument() 产物（完整可打印文档）
     * @param string $outPath 指定产物绝对路径（缺省写入临时目录随机文件名）
     * @return string PDF 文件绝对路径（调用方负责消费与清理）
     * @throws PrintTplDenied 浏览器缺失 / 静态资源缺失 / 生成失败
     */
    public static function fromHtml(string $html, ?string $outPath = null): string
    {
        $dir = self::ensureTempDir();
        self::gcOld($dir);
        $htmlPath = $dir . '/' . bin2hex(random_bytes(8)) . '.html';
        file_put_contents($htmlPath, self::localizeAssets($html));
        $pdfPath = $outPath !== null && $outPath !== '' ? $outPath : ($dir . '/' . bin2hex(random_bytes(8)) . '.pdf');
        try {
            $cmd = escapeshellarg(self::resolveBrowser())
                . ' --headless=new --disable-gpu --no-pdf-header-footer'
                . ' --virtual-time-budget=8000'
                . ' --print-to-pdf=' . escapeshellarg($pdfPath)
                . ' ' . escapeshellarg(self::fileUrl($htmlPath))
                . ' 2>&1';
            exec($cmd, $out, $code);
            // 兜底轮询：个别环境下浏览器派生子进程导致 exec 返回早于文件落盘
            $wait = 0;
            while ((!is_file($pdfPath) || filesize($pdfPath) === 0) && $wait < 15000) {
                usleep(200000);
                $wait += 200000;
            }
            if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
                $msg = implode('; ', array_slice((array) $out, 0, 3));
                throw new PrintTplDenied('PDF 生成失败：无输出文件' . ($msg !== '' ? '（' . $msg . '）' : ''));
            }
            if (substr((string) file_get_contents($pdfPath, false, null, 0, 5), 0, 5) !== '%PDF-') {
                throw new PrintTplDenied('PDF 生成失败：产物不是有效 PDF');
            }
        } finally {
            @unlink($htmlPath);
        }
        return $pdfPath;
    }

    /** 临时目录（不存在则创建）。 */
    private static function ensureTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/printtpl_pdf';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_dir($dir)) {
            throw new PrintTplDenied('无法创建 PDF 临时目录 ' . $dir);
        }
        return $dir;
    }

    /** 路径 → file:// URL（Windows 盘符 / POSIX 均适配）。 */
    private static function fileUrl(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        return str_starts_with($p, '/') ? 'file://' . $p : 'file:///' . $p;
    }

    /** 清理超过保留时长的历史产物。 */
    private static function gcOld(string $dir): void
    {
        $expire = time() - self::TMP_TTL;
        foreach (glob($dir . '/*.pdf') ?: [] as $f) {
            if (is_file($f) && (int) filemtime($f) < $expire) {
                @unlink($f);
            }
        }
    }
}
