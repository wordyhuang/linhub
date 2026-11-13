<?php

declare(strict_types=1);

namespace linhub\host\manifest;

use linhub\host\services\ServiceRegistry;

/**
 * manifest 校验器（T1.1 / 协议规范第七节）。
 *
 * 按序断言 M001–M012，收集全部未通过项。任一失败应阻止安装/加载（调用方据此决定）。
 * 除格式/语义校验外，还依赖 ManifestContext 提供平台侧环境（已装包/占用表/可替换页面/探活清单）。
 */
final class ManifestValidator
{
    public function __construct(private readonly ManifestContext $ctx = new ManifestContext())
    {
    }

    /**
     * 校验 manifest 数组，返回未通过的 M 错误码列表（空则通过）。
     *
     * @param array $m manifest
     * @param array $reasons 输出：错误码 => 具体原因描述列表（引用收集，供调用方在错误消息中展示，如 M015）
     * @param string|null $packageRoot 包根目录；提供时校验 tasks.php 内容（安装期禁用 class_exists）
     * @return list<string>
     */
    public function validate(array $m, array &$reasons = [], ?string $packageRoot = null): array
    {
        $errors = [];
        $name = (string) ($m['name'] ?? '');
        $short = (string) ($m['short'] ?? '');
        $version = (string) ($m['version'] ?? '');

        // M001：基础键必填、格式合法（组/包短名、短名小写字母数字-）
        if (
            $name === '' || $short === '' || $version === ''
            || !preg_match('/^[a-z0-9][a-z0-9\/-]*\/[a-z0-9][a-z0-9-]*$/', $name)
            || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $short)
        ) {
            $errors[] = ManifestError::M001;
        }

        // M002：版本为语义化
        if (!VersionConstraint::isSemVer($version)) {
            $errors[] = ManifestError::M002;
        }

        // M003：依赖无环
        if ($this->hasDependencyCycle($name, (array) ($m['dependencies'] ?? []))) {
            $errors[] = ManifestError::M003;
        }

        // M004：platform.host 兼容当前宿主版本
        $platform = (array) ($m['platform'] ?? []);
        $hostConstraint = (string) ($platform['host'] ?? '');
        if ($hostConstraint !== '' && !VersionConstraint::matches($this->ctx->hostVersion, $hostConstraint)) {
            $errors[] = ManifestError::M004;
            $reasons[ManifestError::M004][] = "platform.host 约束「{$hostConstraint}」不满足当前宿主版本 {$this->ctx->hostVersion}，请调整约束或升级宿主";
        }

        // M005：资源表名前缀隔离且不与已装包冲突
        foreach (($m['resources']['database'] ?? []) as $table) {
            $t = is_array($table) ? (string) ($table['table'] ?? '') : (string) $table;
            if ($t === '') {
                $errors[] = ManifestError::M005;
                continue;
            }
            if (str_starts_with($t, 'lg_') && $short !== '' && str_starts_with($t, 'lg_' . $short . '_')) {
                if (in_array($t, $this->ctx->occupiedTables, true)) {
                    $errors[] = ManifestError::M005;
                }
            } else {
                $errors[] = ManifestError::M005;
            }
        }

        // M006：services.requires 引用的服务存在；M012：服务版本约束满足（事实源 services.php，不依赖 bootstrap）
        $requires = (array) ($m['services']['requires'] ?? []);
        foreach ($requires as $key => $req) {
            if (is_string($key)) {
                // 单键 map：'attachment' => '^1.0'（必须先于 is_string($req) 判断：map 的值也是字符串）
                $id = $key;
                $constraint = (string) $req;
            } elseif (is_string($req)) {
                // 字符串形式：'logging' → 无版本约束
                $id = $req;
                $constraint = '*';
            } else {
                // 兼容嵌套 map：['attachment' => '^1.0']
                $entry = (array) $req;
                $id = (string) key($entry);
                $constraint = (string) reset($entry);
            }
            if ($id === '') {
                continue;
            }
            $current = ServiceRegistry::hostVersion($id);
            if ($current === null && isset($this->ctx->packageServices[$id])) {
                $current = (string) ($this->ctx->packageServices[$id]['version'] ?? '');
            }
            if ($current === null || $current === '') {
                $errors[] = ManifestError::M006;
                continue;
            }
            if ($constraint !== '*' && $constraint !== ''
                && !VersionConstraint::matches($current, $constraint)) {
                $errors[] = ManifestError::M012;
            }
        }

        // M007：ui_replace.target 属于可替换页面清单
        $uiReplace = null;
        if (isset($m['ui_replace'])) {
            $uiReplace = $m['ui_replace'];
        } elseif (isset($m['ui_replace_file'])) {
            $uiReplace = $m['ui_replace_file'];
        }
        if (is_string($uiReplace)) {
            $uiReplace = @include $uiReplace; // 允许传文件路径（如 ui_replace.php）
        }
        foreach ((array) $uiReplace as $ui) {
            if (is_array($ui) && isset($ui['target']) && !in_array($ui['target'], $this->ctx->replaceablePages, true)) {
                $errors[] = ManifestError::M007;
            }
        }

        // M008：subscribes.topic 为合法分段主题（lg.<word>.）
        // 放行：本方前缀 lg.<short>.、跨包外部主题 lg.<otherShort>.、宿主保留前缀 lg.platform.；
        // 统一要求第二段为小写字母数字-单词，保证主题形状一致（N5）。
        foreach ((array) ($m['subscribes'] ?? []) as $sub) {
            $topic = is_array($sub) ? (string) ($sub['topic'] ?? '') : (string) $sub;
            if ($topic === '' || !preg_match('/^lg\.[a-z0-9][a-z0-9-]*\./', $topic)) {
                $errors[] = ManifestError::M008;
            }
        }

        // M009：权限编码 产品:资源:操作
        foreach ($this->loadPermissions($m) as $perm) {
            $code = (string) ($perm['code'] ?? '');
            if (substr_count($code, ':') !== 2 || $code === '') {
                $errors[] = ManifestError::M009;
            }
        }

        // M010：access=public 必须有 access_routes，且 sign 合法
        $access = (string) ($m['access'] ?? 'admin');
        if ($access === 'public') {
            $routes = (array) ($m['access_routes'] ?? []);
            $sign = (string) ($routes['sign'] ?? '');
            if (!isset($routes['routes']) || !is_array($routes['routes']) || $routes['routes'] === []
                || !in_array($sign, ['signature', 'token', 'none'], true)) {
                $errors[] = ManifestError::M010;
            } else {
                // M011：sign=none 仅限 GET 且命中安全探活清单
                if ($sign === 'none') {
                    foreach ($routes['routes'] as $r) {
                        $method = strtoupper(strtok((string) $r, ' '));
                        $path = trim(substr((string) $r, strlen((string) $method)));
                        if ($method !== 'GET' || !in_array($path, $this->ctx->safeProbeRoutes, true)) {
                            $errors[] = ManifestError::M011;
                        }
                    }
                }
            }
        }

        // M013/M014：services.provides 格式与 host 源冲突校验
        foreach ((array) ($m['services']['provides'] ?? []) as $p) {
            $p = (array) $p;
            $pid = (string) ($p['id'] ?? '');
            $pclass = (string) ($p['class'] ?? '');
            $pversion = (string) ($p['version'] ?? '');
            if ($pid === '' || $pclass === '' || !VersionConstraint::isSemVer($pversion)) {
                $errors[] = ManifestError::M013;
                continue;
            }
            if (ServiceRegistry::hostVersion($pid) !== null) {
                $errors[] = ManifestError::M014;
            }
            // M013：可选 desc（服务简介）/ params（参数说明）类型校验
            // desc 须为字符串；params 须为字符串或数组（方法名/条目 => 说明，值为字符串）
            if (isset($p['desc']) && !is_string($p['desc'])) {
                $errors[] = ManifestError::M013;
                continue;
            }
            if (array_key_exists('params', $p)) {
                $params = $p['params'];
                if (is_array($params)) {
                    foreach ($params as $pv) {
                        if (!is_string($pv)) {
                            $errors[] = ManifestError::M013;
                            break;
                        }
                    }
                } elseif (!is_string($params)) {
                    $errors[] = ManifestError::M013;
                }
            }
        }

        // M015：manifest.data 数据随包声明校验（数据随包导出与导入方案 V1.6）
        $this->validateDataSection($m, $errors, $reasons);

        // M016：manifest.tasks 处理器映射声明校验（匿名任务托管第十一章）
        $this->validateTasksSection($m, $packageRoot, $errors, $reasons);

        return array_values(array_unique($errors));
    }

    /**
     * M015：data 段校验——enable/布尔键类型、scope 三档、未知键、range 形态、
     * 服务 id 必须已注册且声明 data_driver（引用不参与数据随包的服务视为包作者错误）。
     * 每个失败分支同步收集具体原因到 $reasons[M015][]，供错误消息展示。
     *
     * @param array $m       manifest
     * @param array $errors  错误累积（引用追加）
     * @param array $reasons 原因累积（引用追加，码 => 描述列表）
     */
    private function validateDataSection(array $m, array &$errors, array &$reasons): void
    {
        $add = function (string $reason) use (&$errors, &$reasons): void {
            $errors[] = ManifestError::M015;
            $reasons[ManifestError::M015][] = $reason;
        };

        if (!array_key_exists('data', $m)) {
            return; // 未声明数据随包：合法
        }
        $data = $m['data'];
        if (!is_array($data)) {
            $add('data 段必须为数组');
            return;
        }
        if (isset($data['enable']) && !is_bool($data['enable'])) {
            $add('data.enable 必须为布尔值');
        }
        if (isset($data['services']) && !is_array($data['services'])) {
            $add('data.services 必须为数组（服务 id => 策略声明）');
            return;
        }
        // 允许的策略键（方案 V1.6 data.services.<id>）
        $allowed = ['enable', 'scope', 'range', 'replace', 'remove_on_uninstall', 'required'];
        foreach ((array) ($data['services'] ?? []) as $id => $pol) {
            if (!is_string($id) || $id === '' || !is_array($pol)) {
                $add(is_string($id) && $id !== ''
                    ? "data.services.{$id} 的策略声明必须为数组"
                    : 'data.services 存在非法条目（键须为非空服务 id 字符串，值须为数组）');
                continue;
            }
            foreach (array_keys($pol) as $k) {
                if (!in_array((string) $k, $allowed, true)) {
                    $add("data.services.{$id} 含未知策略键「{$k}」（允许：" . implode('、', $allowed) . '）');
                }
            }
            foreach (['enable', 'replace', 'remove_on_uninstall', 'required'] as $bkey) {
                if (isset($pol[$bkey]) && !is_bool($pol[$bkey])) {
                    $add("data.services.{$id}.{$bkey} 必须为布尔值");
                }
            }
            if (isset($pol['scope']) && !in_array($pol['scope'], ['seed', 'all', 'range'], true)) {
                $add("data.services.{$id}.scope 必须为 seed、all、range 之一");
            }
            if (isset($pol['range']) && (!is_array($pol['range']) || isset($pol['range'][0]))) {
                // range 必须为 ['from' => 'Y-m-d', 'to' => 'Y-m-d'] 形态的 map（list 或标量均非法）
                $add("data.services.{$id}.range 必须为 ['from' => 'Y-m-d', 'to' => 'Y-m-d'] 形态的映射");
            }
            // 服务 id 必须已注册于 services.php 且声明了 data_driver
            $entry = ServiceRegistry::hostList()[$id] ?? null;
            if ($entry === null || (string) ($entry['data_driver'] ?? '') === '') {
                $add("data.services.{$id}：服务未注册或未声明 data_driver，不参与数据随包（需在平台 services.php 注册该服务并声明 data_driver）");
            }
        }
    }

    /** 依赖环检测：以自身为根，DFS 检测经已装依赖图是否回到自身。 */
    private function hasDependencyCycle(string $name, array $deps): bool
    {
        // 自依赖
        foreach ($deps as $dep => $_c) {
            if ($dep === $name) {
                return true;
            }
        }
        // 经已装图是否回到自身
        $visited = [];
        $stack = array_keys($deps);
        while ($stack !== []) {
            $cur = array_shift($stack);
            if ($cur === $name) {
                return true;
            }
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            foreach (array_keys($this->ctx->dependencyGraph[$cur] ?? []) as $next) {
                $stack[] = $next;
            }
        }
        return false;
    }

    /**
     * M016：manifest.tasks 处理器映射声明校验。
     *
     * 只做静态校验（AC-46 硬约束：安装期禁止 class_exists / interface_exists）：
     *  - `tasks` 必须是包根下的裸文件名（无目录分隔、无 ..、后缀 .php）；
     *  - 提供包根时 include 该文件，必须返回数组；
     *  - 键须匹配 `^[a-z][a-z0-9._-]{0,63}$`；
     *  - 值须为 FQCN 字符串且以本包 composer.json 的 PSR-4 前缀开头（排除函数名/闭包/跨包类）。
     * 文件不存在只告警不阻断（AC-46）。
     *
     * @param array $m manifest
     * @param string|null $packageRoot 包根目录
     * @param array $errors 错误累积（引用追加）
     * @param array $reasons 原因累积（引用追加，码 => 描述列表）
     */
    private function validateTasksSection(array $m, ?string $packageRoot, array &$errors, array &$reasons): void
    {
        if (!array_key_exists('tasks', $m)) {
            return;
        }
        $add = function (string $reason) use (&$errors, &$reasons): void {
            $errors[] = ManifestError::M016;
            $reasons[ManifestError::M016][] = $reason;
        };

        $file = $m['tasks'];
        if (!is_string($file) || trim($file) === '') {
            $add('manifest.tasks 必须为处理器文件名（字符串），缺省即 tasks.php');
            return;
        }
        $file = trim($file);
        if (preg_match('/^[A-Za-z0-9_-]+\.php$/', $file) !== 1) {
            $add("manifest.tasks 文件名「{$file}」不合法：只允许包根下的裸 .php 文件名，不含目录分隔符与 ..");
            return;
        }

        if ($packageRoot === null || $packageRoot === '') {
            return;
        }

        $path = rtrim($packageRoot, '/\\') . '/' . $file;
        if (!is_file($path)) {
            // 声明了 tasks 但文件缺失：只告警不阻断，运行期按 invalid_handler 处理
            return;
        }

        $map = @include $path;
        if (!is_array($map)) {
            $add("{$file} 必须只返回数组（键=处理器标识，值=实现类 FQCN）");
            return;
        }

        $prefixes = $this->psr4Prefixes($packageRoot);
        foreach ($map as $key => $class) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $key) !== 1) {
                $add("处理器标识「{$key}」不合法：须匹配 ^[a-z][a-z0-9._-]{0,63}$");
                continue;
            }
            if (!is_string($class) || $class === '') {
                $add("处理器「{$key}」的值必须为类名字符串，不接受闭包、函数名或数组回调");
                continue;
            }
            $fqcn = ltrim($class, '\\');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $fqcn) !== 1) {
                $add("处理器「{$key}」的值「{$class}」不是合法 FQCN（须为 Namespace\\\\Class 形式）");
                continue;
            }
            if ($prefixes !== [] && !$this->matchesPrefix($fqcn, $prefixes)) {
                $add("处理器「{$key}」的类「{$fqcn}」不在本包命名空间内（期望前缀：" . implode(' / ', $prefixes) . '）');
            }
        }
    }

    /** 读取 composer.json 的 autoload.psr-4 前缀（去尾部反斜杠，空前缀忽略）。 */
    private function psr4Prefixes(string $packageRoot): array
    {
        $file = rtrim($packageRoot, '/\\') . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($file), true);
        $psr4 = is_array($json) ? ($json['autoload']['psr-4'] ?? []) : [];
        if (!is_array($psr4)) {
            return [];
        }
        $out = [];
        foreach (array_keys($psr4) as $prefix) {
            $prefix = ltrim((string) $prefix, '\\');
            if ($prefix !== '') {
                $out[] = rtrim($prefix, '\\') . '\\';
            }
        }
        return $out;
    }

    private function matchesPrefix(string $fqcn, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** 加载权限声明（支持 manifests 内联数组或文件路径）。 */
    private function loadPermissions(array $m): array
    {
        $p = $m['permissions'] ?? [];
        if (is_array($p)) {
            return $p;
        }
        if (is_string($p) && is_file($p)) {
            $v = include $p;
            return is_array($v) ? $v : [];
        }
        return [];
    }
}