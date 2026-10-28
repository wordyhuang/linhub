<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

use think\facade\Env;

/**
 * 平台 / workdesktop 版本检测与自升级服务
 *
 * 两类升级对象相互独立、各自检测、各自升级：
 *  - target = host：覆盖宿主代码目录 code/host（linhub/* 源码与 public/static/vendor 等，
 *    不含 runtime/.env），新版本号由升级包内 version 声明更新。
 *  - target = workdesktop：仅覆盖 public/static/vendor/workdesktop-ai 前端 UMD 资源，
 *    当前版本记录在 data/platform-versions.json。
 *
 * 版本检测地址在 .env 配置（留空表示未配置，不检测）：
 *  - UPDATE_HOST_CHECK_URL：平台自定义检测接口，返回标准 JSON
 *    {"version":"1.3.0","download_url":"https://.../pkg.zip","release_note":"..."}
 *  - UPDATE_WORKDESKTOP_PACKAGE_URL：workdesktop 前端「包发布地址」，平台自动检测，
 *    直接填 npm 包页面地址即可，如 https://www.npmjs.com/package/workdesktop-ai；
 *    也支持 scoped 包与 registry 直链。平台据此请求 npm registry 元数据取最新版本与 tgz。
 *
 * 升级流程（两类包格式不同）：
 *  - host：下载 zip → 校验 manifest(target/version) → 备份 → 解压合并覆盖（跳过 .env/runtime）。
 *  - workdesktop：下载 npm tgz（PharData 解压，不依赖 zip 扩展）→ 校验包内 package.json 的
 *    name/version → 备份 → package/dist 整目录覆盖 public/static/vendor/workdesktop-ai。
 * 备份统一落在 data/platform-backups。任何一步失败抛出异常，已完成的备份保留以便人工回滚。
 *
 * workdesktop 回退（rollbackWorkdesktop）：来源有二——
 *  - 本地备份：直接用 platform-backups/workdesktop-<ver>-<时间戳> 整目录还原（离线、无需扩展）；
 *  - npm 历史版本：下载 registry 中低于当前版本的正式版 tgz，复用升级覆盖链路（需 phar+zlib）。
 * 两种回退执行前都会再安全备份一次当前 vendor，使回退本身可撤销。host 不提供回退。
 */
final class PlatformUpdater
{
    public const TARGET_HOST = 'host';
    public const TARGET_WORKDESKTOP = 'workdesktop';

    /** workdesktop 默认 npm 包（前端 UMD 组件库） */
    private const WORDDESKTOP_NPM_PACKAGE = 'workdesktop-ai';

    /** 官方 npm registry 元数据地址（{pkg} 占位） */
    private const NPM_REGISTRY_META = 'https://registry.npmjs.org/%s';

    /** 网络请求超时（秒）：检测 */
    private const HTTP_TIMEOUT = 15;

    /** 网络请求超时（秒）：下载升级包 */
    private const DOWNLOAD_TIMEOUT = 300;

    /** @var string 宿主根目录 code/host（绝对路径，正斜杠） */
    private string $hostRoot;

    /** @var string 最近一次 npm 元数据拉取错误（回退清单尽力而为，空=无错误/未尝试） */
    private string $npmMetaError = '';

    public function __construct(?string $hostRoot = null)
    {
        // 默认：本文件位于 code/host/linhub/host/src/lifecycle，回溯 5 级到 code/host
        $this->hostRoot = $this->normalize($hostRoot ?? dirname(__DIR__, 4));
    }

    /**
     * 当前 workdesktop（前端 UMD）版本。
     * 记录在 data/platform-versions.json；首次无记录时回退到内置基线版本。
     */
    public function workdesktopVersion(): string
    {
        $versions = $this->readVersionRecords();
        $v = trim((string) ($versions['workdesktop'] ?? ''));
        return $v !== '' ? $v : $this->fallbackWorkdesktopVersion();
    }

    /** 内置基线版本：与 code/frontend/package.json 依赖 workdesktop-ai 对齐。 */
    public function fallbackWorkdesktopVersion(): string
    {
        return '1.2.4';
    }

    /**
     * 检测最新版本。
     *
     * @return array{version:string,downloadUrl:string,releaseNote:string,configured:bool}
     *         configured=false 表示 env 未配置检测地址
     *
     * @throws \RuntimeException 网络失败或返回无法解析
     */
    public function checkLatest(string $target): array
    {
        return $target === self::TARGET_WORKDESKTOP
            ? $this->checkWorkdesktopFromNpm()
            : $this->checkHostFromJson();
    }

    /**
     * 平台版本检测：.env 配置的自定义 JSON 检测接口。
     *
     * @return array{version:string,downloadUrl:string,releaseNote:string,configured:bool}
     */
    private function checkHostFromJson(): array
    {
        $url = trim((string) Env::get('UPDATE_HOST_CHECK_URL', ''));
        if ($url === '') {
            return ['version' => '', 'downloadUrl' => '', 'releaseNote' => '', 'configured' => false];
        }

        $body = $this->httpGet($url);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('版本检测返回无法解析：' . substr($body, 0, 200));
        }
        $version = trim((string) ($json['version'] ?? ''));
        if ($version === '') {
            throw new \RuntimeException('版本检测返回缺少 version 字段');
        }

        return [
            'version'     => $version,
            'downloadUrl' => trim((string) ($json['download_url'] ?? $json['downloadUrl'] ?? '')),
            'releaseNote' => (string) ($json['release_note'] ?? $json['releaseNote'] ?? ''),
            'configured'  => true,
        ];
    }

    /**
     * workdesktop 版本检测：从「包发布地址」（npm 包页面/registry 地址）解析包名，
     * 请求 npm registry 元数据，取 dist-tags.latest 与其 tarball。
     * 包发布地址留空时使用内置默认包 workdesktop-ai（即默认始终检测官方包）。
     *
     * @return array{version:string,downloadUrl:string,releaseNote:string,configured:bool}
     */
    private function checkWorkdesktopFromNpm(): array
    {
        $meta = $this->fetchWorkdesktopNpmMeta();
        $packageName = (string) ($meta['name'] ?? self::WORDDESKTOP_NPM_PACKAGE);

        $name = (string) ($meta['name'] ?? '');
        if ($name !== '' && $name !== $packageName) {
            throw new \RuntimeException("npm 包名不匹配：期望 {$packageName}，实际 {$name}");
        }
        $latest = trim((string) ($meta['dist-tags']['latest'] ?? ''));
        if ($latest === '') {
            throw new \RuntimeException('npm registry 元数据缺少 dist-tags.latest');
        }
        $versionMeta = $meta['versions'][$latest] ?? null;
        if (!is_array($versionMeta)) {
            throw new \RuntimeException("npm registry 缺少版本 {$latest} 的元数据");
        }
        $tarball = trim((string) ($versionMeta['dist']['tarball'] ?? ''));
        if ($tarball === '') {
            throw new \RuntimeException("npm 版本 {$latest} 缺少 dist.tarball");
        }

        // release note：优先取该版本发布说明，无则用包描述
        $note = (string) ($meta['versions'][$latest]['release_note']
            ?? $meta['versions'][$latest]['releaseNote']
            ?? $meta['description']
            ?? '');

        return [
            'version'     => $latest,
            'downloadUrl' => $tarball,
            'releaseNote' => $note,
            'configured'  => true,
        ];
    }

    /**
     * 按 .env 配置的包发布地址解析包名并请求 npm registry 元数据。
     *
     * @return array<string, mixed> registry 元数据
     *
     * @throws \RuntimeException 包名无法解析、网络失败或返回无法解析
     */
    private function fetchWorkdesktopNpmMeta(): array
    {
        $configured = trim((string) Env::get('UPDATE_WORKDESKTOP_PACKAGE_URL', ''));
        $packageName = $configured === ''
            ? self::WORDDESKTOP_NPM_PACKAGE
            : $this->parseNpmPackageName($configured);
        if ($packageName === '') {
            throw new \RuntimeException('无法从包发布地址解析 npm 包名：' . $configured);
        }

        $metaUrl = sprintf(self::NPM_REGISTRY_META, rawurlencode($packageName));
        // scoped 包 rawurlencode 会把斜杠也编码，registry 需要保留 @scope/name 形态的斜杠
        $metaUrl = str_replace('%2F', '/', $metaUrl);
        $body = $this->httpGet($metaUrl);
        $meta = json_decode($body, true);
        if (!is_array($meta)) {
            throw new \RuntimeException('npm registry 元数据无法解析：' . substr($body, 0, 200));
        }
        return $meta;
    }

    /**
     * workdesktop 可回退版本清单：本地升级前备份 + npm 历史稳定版。
     *
     *  - backups：扫描 data/platform-backups 下 workdesktop-<version>-<时间戳> 目录，
     *    新版本在前；离线可用、无需 phar/zlib。
     *  - npmVersions：registry 中低于当前版本的正式（非 prerelease）版本，旧版本在前，
     *    回退时复用 tgz 下载链路（需 phar+zlib）。
     * npm 元数据拉取失败不阻断本地备份清单，仅回传 npmError。
     *
     * @return array{current:string,backups:array<int,array{version:string,backup:string,time:string}>,npmVersions:array<int,array{version:string,downloadUrl:string}>,npmError:string}
     */
    public function workdesktopRollbackOptions(): array
    {
        $current = $this->workdesktopVersion();
        $currentDigits = $this->digits($current);

        return [
            'current'     => $current,
            'backups'     => $this->scanWorkdesktopBackups(),
            'npmVersions' => $this->listOlderNpmVersions($currentDigits),
            'npmError'    => $this->npmMetaError,
        ];
    }

    /** 扫描 workdesktop 升级前备份目录（目录名 workdesktop-<version>-<YmdHis>），按时间倒序。 */
    private function scanWorkdesktopBackups(): array
    {
        $base = $this->hostRoot . '/data/platform-backups';
        if (!is_dir($base)) {
            return [];
        }
        $items = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_starts_with($entry, 'workdesktop-')) {
                continue;
            }
            $dir = $base . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            // workdesktop-<version>-<14位时间戳>；version 自身不含时间戳尾段
            if (!preg_match('/^workdesktop-(.+)-(\d{14})$/', $entry, $m)) {
                continue;
            }
            // 备份须含 UMD 入口，防止空目录/残目录被当作可回退点
            if (!is_file($dir . '/index.umd.js')) {
                continue;
            }
            $ts = $m[2];
            $items[] = [
                'version' => $m[1],
                'backup'  => $entry,
                'time'    => $this->formatTimestamp($ts),
                '_sort'   => $ts,
            ];
        }
        // 同版本多份备份时按时间戳倒序，最新备份在前
        usort($items, static fn($a, $b) => strcmp($b['_sort'], $a['_sort']));
        foreach ($items as &$it) {
            unset($it['_sort']);
        }
        unset($it);
        return $items;
    }

    /**
     * 从 registry 元数据取低于当前版本的正式版本（剔除 prerelease），旧版本在前。
     *
     * @return array<int,array{version:string,downloadUrl:string}>
     */
    private function listOlderNpmVersions(string $currentDigits): array
    {
        $this->npmMetaError = '';
        try {
            $meta = $this->fetchWorkdesktopNpmMeta();
        } catch (\Throwable $e) {
            $this->npmMetaError = $e->getMessage();
            return [];
        }
        $versions = $meta['versions'] ?? [];
        if (!is_array($versions)) {
            return [];
        }
        $out = [];
        foreach ($versions as $ver => $info) {
            $ver = (string) $ver;
            // 仅正式版：版本号不含连字符（1.2.3 正式；1.2.4-beta.1 等 prerelease 排除）
            if (str_contains($ver, '-')) {
                continue;
            }
            if (version_compare($this->digits($ver), $currentDigits, '>=')) {
                continue;
            }
            $tarball = trim((string) ($info['dist']['tarball'] ?? ''));
            if ($tarball === '') {
                continue;
            }
            $out[] = ['version' => $ver, 'downloadUrl' => $tarball];
        }
        usort($out, static fn($a, $b) => version_compare($a['version'], $b['version']));
        return $out;
    }

    /** 14 位时间戳 YmdHis 格式化为 Y-m-d H:i:s；无法解析时原样返回。 */
    private function formatTimestamp(string $ts): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $ts, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        return $ts;
    }

    /**
     * workdesktop 回退到指定旧版本。
     *
     * @param string $source backup=使用本地备份；npm=下载指定版本 tgz
     * @param string $ref    backup 时为备份目录名；npm 时为目标版本号
     * @param string $downloadUrl source=npm 时的 tgz 地址（由前端从清单回传）
     *
     * @return array{target:string,from:string,to:string,source:string,backup:string}
     *
     * @throws \RuntimeException 任一环节失败
     */
    public function rollbackWorkdesktop(string $source, string $ref, string $downloadUrl = ''): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \RuntimeException('未指定回退版本');
        }
        $from = $this->workdesktopVersion();

        if ($source === 'backup') {
            return $this->rollbackWorkdesktopFromBackup($ref, $from);
        }
        if ($source === 'npm') {
            // 回退同样走 tgz 下载→校验→整目录覆盖链路（与升级一致，仅方向是旧版）
            $result = $this->upgradeWorkdesktop(trim($downloadUrl), $ref, $from);
            $result['source'] = 'npm';
            return $result;
        }
        throw new \InvalidArgumentException("未知回退来源：{$source}");
    }

    /** 从本地备份目录整目录还原 vendor，回退前先安全备份当前版本。 */
    private function rollbackWorkdesktopFromBackup(string $backupName, string $from): array
    {
        // 只允许单段目录名，杜绝路径穿越（../、反斜杠、绝对路径）
        if ($backupName !== basename($backupName)
            || !preg_match('/^workdesktop-.+-\d{14}$/', $backupName)) {
            throw new \RuntimeException('非法的备份标识');
        }
        $source = $this->hostRoot . '/data/platform-backups/' . $backupName;
        if (!is_dir($source) || !is_file($source . '/index.umd.js')) {
            throw new \RuntimeException("备份不存在或已损坏：{$backupName}");
        }
        if (!preg_match('/^workdesktop-(.+)-\d{14}$/', $backupName, $m)) {
            throw new \RuntimeException('备份目录名无法解析版本');
        }
        $toVersion = $m[1];

        $vendor = $this->workdesktopDir();
        // 回退前安全备份当前版本（vendor 为空时跳过备份），保证本次回退本身也可撤销
        $safetyBackup = '';
        if (is_dir($vendor) && count(array_diff(scandir($vendor) ?: [], ['.', '..'])) > 0) {
            $safetyBackup = $this->hostRoot . '/data/platform-backups/workdesktop-' . $from . '-' . date('YmdHis');
            $this->backupCurrent(self::TARGET_WORKDESKTOP, $safetyBackup);
        }

        $this->removePath($vendor);
        $this->copyDir($source, $vendor);
        $this->writeWorkdesktopVersion($toVersion);

        return [
            'target' => self::TARGET_WORKDESKTOP,
            'from'   => $from,
            'to'     => $toVersion,
            'source' => 'backup',
            'backup' => $safetyBackup,
        ];
    }

    /**
     * 从「包发布地址」解析 npm 包名。
     * 支持：
     *  - npm 站点页面 https://www.npmjs.com/package/workdesktop-ai
     *    及 scoped https://www.npmjs.com/package/@scope/name
     *  - registry 直链 https://registry.npmjs.org/workdesktop-ai（含 /<version> 尾部）
     *  - 直接填包名 workdesktop-ai 或 @scope/name
     * 另对国内镜像（registry.npmmirror.com）同样按路径提取。
     */
    private function parseNpmPackageName(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        // 直接就是包名（无协议无主机）
        if (!preg_match('~^https?://~i', $input) && strpos($input, '/') !== false
            && preg_match('~^(@[a-z0-9\-_\.]+/)?[a-z0-9\-_\.]+$~i', $input)) {
            return $input;
        }
        if (!preg_match('~^https?://~i', $input) && strpos($input, '/') === false
            && preg_match('~^[a-z0-9\-_\.]+$~i', $input)) {
            return $input;
        }

        $path = (string) parse_url($input, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));

        // /package/<name>[/...] 或 /package/<@scope>/<name>
        if (count($segments) >= 2 && strtolower($segments[0]) === 'package') {
            if (str_starts_with($segments[1], '@')) {
                return isset($segments[2]) ? $segments[1] . '/' . $segments[2] : '';
            }
            return $segments[1];
        }

        // registry 直链：/<name> 或 /<@scope>/<name>[/<version>]
        if (count($segments) >= 1) {
            if (str_starts_with($segments[0], '@')) {
                return isset($segments[1]) ? $segments[0] . '/' . $segments[1] : '';
            }
            // 无 scope：第一段即包名（可能后面跟 /<version>）
            return preg_match('~^[a-z0-9\-_\.]+$~i', $segments[0]) ? $segments[0] : '';
        }

        return '';
    }

    /**
     * 判断目标版本是否比当前版本新（语义化版本比较）。
     */
    public function isNewer(string $target, string $latestVersion): bool
    {
        $current = $target === self::TARGET_HOST
            ? \linhub\host\Version::current()
            : $this->workdesktopVersion();
        return version_compare($this->digits($latestVersion), $this->digits($current), '>');
    }

    /**
     * 执行升级：下载 → 校验 → 备份 → 覆盖 → 记录版本。
     *
     * @return array{target:string,from:string,to:string,backup:string} 升级结果摘要
     *
     * @throws \RuntimeException 任一环节失败
     */
    public function upgrade(string $target, string $downloadUrl, string $toVersion): array
    {
        $this->assertTarget($target);
        $downloadUrl = trim($downloadUrl);
        $toVersion = trim($toVersion);
        if ($downloadUrl === '' || $toVersion === '') {
            throw new \RuntimeException('升级包地址或目标版本为空');
        }

        $from = $target === self::TARGET_HOST
            ? \linhub\host\Version::current()
            : $this->workdesktopVersion();

        return $target === self::TARGET_WORKDESKTOP
            ? $this->upgradeWorkdesktop($downloadUrl, $toVersion, $from)
            : $this->upgradeHost($downloadUrl, $toVersion, $from);
    }

    /**
     * host 升级：下载自定义 zip（manifest + payload），需 zip 扩展。
     *
     * @return array{target:string,from:string,to:string,backup:string}
     */
    private function upgradeHost(string $downloadUrl, string $toVersion, string $from): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP 未启用 zip 扩展，无法解压平台升级包');
        }

        $workDir = $this->hostRoot . '/runtime/platform-update';
        $this->ensureDir($workDir);
        $zipPath = $workDir . '/host-' . $toVersion . '.zip';
        $extractDir = $workDir . '/host-' . $toVersion;

        // 清理上次残留
        $this->removePath($extractDir);
        $this->httpDownload($downloadUrl, $zipPath);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException("无法打开升级包：{$zipPath}");
            }
            if (!$zip->extractTo($extractDir)) {
                $zip->close();
                throw new \RuntimeException('升级包解压失败');
            }
            $zip->close();

            // 定位 payload 根（包内 manifest.json 与 payload/ 平级；兼容包内直接平铺）
            $root = $this->locatePayloadRoot($extractDir, self::TARGET_HOST, $toVersion);
            if ($root === null) {
                throw new \RuntimeException('升级包缺少 manifest.json 或 target/version 不匹配');
            }
            $payload = rtrim($root, '/\\') . '/payload';
            if (!is_dir($payload)) {
                throw new \RuntimeException('升级包缺少 payload 目录');
            }

            // 备份当前目标
            $backupDir = $this->hostRoot . '/data/platform-backups/host-' . $from . '-' . date('YmdHis');
            $this->backupCurrent(self::TARGET_HOST, $backupDir);

            // 覆盖落地（host 白名单合并）
            $this->applyHostPayload($payload);
            // host 版本以升级包 payload 内的 Version.php 为准（随覆盖更新），无需额外写记录
        } catch (\Throwable $e) {
            // 失败也清理解压临时物；下载的 zip 保留以便排查，备份（若已建）保留以便人工回滚
            $this->removePath($extractDir);
            throw $e;
        }

        $this->removePath($extractDir);
        @unlink($zipPath);

        return ['target' => self::TARGET_HOST, 'from' => $from, 'to' => $toVersion, 'backup' => $backupDir];
    }

    /**
     * workdesktop 升级：下载 npm tgz（PharData 解压，依赖 phar+zlib，不依赖 zip 扩展），
     * 校验包内 package.json 的 name/version，将 package/dist 整目录覆盖到 vendor。
     *
     * @return array{target:string,from:string,to:string,backup:string}
     */
    private function upgradeWorkdesktop(string $downloadUrl, string $toVersion, string $from): array
    {
        if (!class_exists(\PharData::class) || !extension_loaded('zlib')) {
            throw new \RuntimeException('PHP 未启用 phar/zlib 扩展，无法解压 workdesktop 升级包(tgz)');
        }

        $workDir = $this->hostRoot . '/runtime/platform-update';
        $this->ensureDir($workDir);
        $tgzPath = $workDir . '/workdesktop-' . $toVersion . '.tgz';
        $extractDir = $workDir . '/workdesktop-' . $toVersion;

        // 清理上次残留
        $this->removePath($extractDir);
        $this->httpDownload($downloadUrl, $tgzPath);

        try {
            // tgz = gzip(tar)：PharData 直接识别 gz 压缩的 tar，extractTo 得到 package/ 目录
            try {
                $tar = new \PharData($tgzPath);
                $tar->extractTo($extractDir, null, true);
            } catch (\Throwable $e) {
                throw new \RuntimeException('workdesktop 升级包解压失败：' . $e->getMessage(), 0, $e);
            }

            // npm 包固定一层 package/ 根目录
            $packageDir = $extractDir . '/package';
            $packageJson = $packageDir . '/package.json';
            if (!is_file($packageJson)) {
                throw new \RuntimeException('升级包缺少 package/package.json');
            }
            $pkg = json_decode((string) file_get_contents($packageJson), true);
            if (!is_array($pkg)) {
                throw new \RuntimeException('升级包 package.json 无法解析');
            }
            $pkgVersion = trim((string) ($pkg['version'] ?? ''));
            // 强校验版本，防止下载地址与目标版本不符；包名仅用于错误兜底
            if ($pkgVersion !== $toVersion) {
                throw new \RuntimeException("升级包版本不匹配：声明 {$toVersion}，包内 {$pkgVersion}");
            }
            $dist = $packageDir . '/dist';
            if (!is_dir($dist)) {
                throw new \RuntimeException('升级包缺少 package/dist 目录');
            }
            if (!is_file($dist . '/index.umd.js')) {
                throw new \RuntimeException('升级包 dist 缺少 index.umd.js');
            }

            // 备份当前 vendor
            $backupDir = $this->hostRoot . '/data/platform-backups/workdesktop-' . $from . '-' . date('YmdHis');
            $this->backupCurrent(self::TARGET_WORKDESKTOP, $backupDir);

            // 整目录覆盖：先清空旧 vendor 再落 dist（npm 产物文件名带 hash，残留旧文件无意义）
            $vendor = $this->workdesktopDir();
            $this->removePath($vendor);
            $this->copyDir($dist, $vendor);

            $this->writeWorkdesktopVersion($toVersion);
        } catch (\Throwable $e) {
            // 失败清理解压临时物；tgz 保留排查，备份保留回滚（vendor 已被清空时可用备份恢复）
            $this->removePath($extractDir);
            throw $e;
        }

        $this->removePath($extractDir);
        @unlink($tgzPath);

        return ['target' => self::TARGET_WORKDESKTOP, 'from' => $from, 'to' => $toVersion, 'backup' => $backupDir];
    }

    /**
     * 在解压目录中定位含 manifest.json 的根（包可能带一层顶层目录）。
     * 校验 manifest 的 target/version。
     */
    private function locatePayloadRoot(string $extractDir, string $target, string $version): ?string
    {
        $direct = $extractDir . '/manifest.json';
        if (is_file($direct) && $this->manifestMatches($direct, $target, $version)) {
            return $extractDir;
        }
        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $extractDir . '/' . $entry;
            if (is_dir($candidate) && is_file($candidate . '/manifest.json')
                && $this->manifestMatches($candidate . '/manifest.json', $target, $version)) {
                return $candidate;
            }
        }
        return null;
    }

    private function manifestMatches(string $file, string $target, string $version): bool
    {
        $m = json_decode((string) file_get_contents($file), true);
        return is_array($m)
            && (string) ($m['target'] ?? '') === $target
            && trim((string) ($m['version'] ?? '')) === $version;
    }

    /** 备份当前升级目标。 */
    private function backupCurrent(string $target, string $backupDir): void
    {
        if ($target === self::TARGET_WORKDESKTOP) {
            $src = $this->workdesktopDir();
            if (is_dir($src)) {
                $this->copyDir($src, $backupDir);
            }
            return;
        }
        // host：备份会被覆盖的交付目录（不含 runtime/.env）
        foreach ($this->hostPayloadTopDirs() as $dir) {
            if (is_dir($dir)) {
                $name = basename($dir);
                $this->copyDir($dir, $backupDir . '/' . $name);
            }
        }
    }

    /** 将 host payload 合并覆盖到宿主：只合并白名单顶层项，杜绝误覆盖 runtime / .env / 白名单外目录。 */
    private function applyHostPayload(string $payload): void
    {
        $allowed = array_map('basename', $this->hostPayloadTopDirs());
        foreach (scandir($payload) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !in_array($entry, $allowed, true)) {
                continue;
            }
            $this->mergeDir($payload . '/' . $entry, $this->hostRoot . '/' . $entry);
        }
    }

    /** host 升级白名单顶层交付目录（与备份口径一致）。 */
    private function hostPayloadTopDirs(): array
    {
        return [
            $this->hostRoot . '/linhub',
            $this->hostRoot . '/config',
            $this->hostRoot . '/route',
            $this->hostRoot . '/public',
        ];
    }

    private function workdesktopDir(): string
    {
        return $this->hostRoot . '/public/static/vendor/workdesktop-ai';
    }

    private function assertTarget(string $target): void
    {
        if (!in_array($target, [self::TARGET_HOST, self::TARGET_WORKDESKTOP], true)) {
            throw new \InvalidArgumentException("未知升级目标：{$target}");
        }
    }

    /** 读取 data/platform-versions.json。 */
    private function readVersionRecords(): array
    {
        $file = $this->hostRoot . '/data/platform-versions.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function writeWorkdesktopVersion(string $version): void
    {
        $file = $this->hostRoot . '/data/platform-versions.json';
        $this->ensureDir(dirname($file));
        $data = $this->readVersionRecords();
        $data['workdesktop'] = $version;
        $data['workdesktop_updated_at'] = date('c');
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $file);
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException('版本检测请求失败：' . $err);
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException("版本检测返回 HTTP {$code}");
            }
            return (string) $resp;
        }

        $ctx = stream_context_create(['http' => ['timeout' => self::HTTP_TIMEOUT, 'follow_location' => 1]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException('版本检测请求失败（file_get_contents）');
        }
        return $resp;
    }

    private function httpDownload(string $url, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (function_exists('curl_init')) {
            $fp = fopen($dest, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (!$ok) {
                throw new \RuntimeException('升级包下载失败：' . $err);
            }
            return;
        }

        $ctx = stream_context_create(['http' => ['timeout' => 300, 'follow_location' => 1]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            throw new \RuntimeException('升级包下载失败（file_get_contents）');
        }
        file_put_contents($dest, $data);
    }

    /** 递归合并目录：源覆盖目标中同名文件，源中新增的目录/文件补齐，不删除目标独有文件。 */
    private function mergeDir(string $src, string $dst): void
    {
        $this->ensureDir($dst);
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                $this->mergeDir($s, $d);
            } else {
                $this->ensureDir(dirname($d));
                copy($s, $d);
            }
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        $this->ensureDir($dst);
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                $this->copyDir($s, $d);
            } else {
                copy($s, $d);
            }
        }
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path) && !is_dir($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建目录：{$dir}");
        }
    }

    /** 仅保留版本号数字点段，避免 version_compare 被前缀干扰。 */
    private function digits(string $version): string
    {
        if (preg_match('/\d+(?:\.\d+)+/', $version, $m)) {
            return $m[0];
        }
        return ltrim($version, 'vV');
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
