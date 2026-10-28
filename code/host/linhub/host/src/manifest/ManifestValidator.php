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
     * @return list<string>
     */
    public function validate(array $m): array
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

        return array_values(array_unique($errors));
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