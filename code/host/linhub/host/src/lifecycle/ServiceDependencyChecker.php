<?php

declare(strict_types=1);

namespace linhub\host\lifecycle;

use linhub\host\manifest\VersionConstraint;
use linhub\host\services\ServiceRegistry;

/**
 * 服务契约依赖校验（V7.29）
 *
 * 校验 manifest.services.requires 声明的宿主服务是否存在、版本约束是否满足。
 * 版本事实源为 services.php（经 ServiceRegistry::hostVersion 读取），不依赖 bootstrap 内存态。
 * 不满足时返回错误信息，安装/升级前硬拦截；运行时另有 ServiceRegistry::resolveAll 兜底。
 */
final class ServiceDependencyChecker
{
    /**
     * 校验服务依赖声明。
     *
     * 支持三种声明形式：
     *  - 字符串：'logging'（无版本约束）
     *  - 单键 map：'attachment' => '^1.0'
     *  - 嵌套 map（兼容协议写法）：['attachment' => '^1.0']
     *
     * @param array $requires manifest.services.requires 数组
     * @param array|null $packageServices 已装功能包 provides 镜像（id => [class, version]）；null 时读生产默认
     * @return string|null 全部满足返回 null，否则返回首个不满足项的错误信息
     */
    public function check(array $requires, ?array $packageServices = null): ?string
    {
        $packageServices = $packageServices ?? ServiceRegistry::packageProvides();
        foreach ($requires as $key => $req) {
            if (is_string($key)) {
                // 单键 map：'attachment' => '^1.0'（必须先于 is_string($req) 判断：map 的值也是字符串）
                $id = $key;
                $constraint = (string) $req;
            } elseif (is_string($req)) {
                $id = $req;
                $constraint = '*';
            } else {
                $entry = (array) $req;
                $id = (string) key($entry);
                $constraint = (string) reset($entry);
            }
            if ($id === '') {
                continue;
            }
            $current = ServiceRegistry::hostVersion($id);
            if ($current === null && isset($packageServices[$id])) {
                $current = (string) ($packageServices[$id]['version'] ?? '');
            }
            if ($current === null || $current === '') {
                return "服务依赖不满足：缺少服务 {$id}";
            }
            if ($constraint !== '*' && $constraint !== ''
                && !VersionConstraint::matches($current, $constraint)) {
                return "服务依赖不满足：{$id} 要求 {$constraint}，当前 {$current}";
            }
        }
        return null;
    }
}
