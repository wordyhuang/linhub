# LinHub 服务接入指南 · cache 缓存服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 cache 缓存服务的契约方法、接入方式与使用要点。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 cache 一个服务；通用接入流程见总路由。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 使用要点与避坑
5. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `cache` |
| 契约接口 | `linhub\host\services\cache\Cache` |
| 宿主实现 | `linhub\host\services\cache\CacheService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['cache'], ...]

// 控制器/服务内
$cache = HostServiceApi::getService($this->app, 'cache', 'demo');
$cache->set('demo.hot_list', $list, 300);   // 5 分钟
$hot = $cache->get('demo.hot_list', []);
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `get` | `get(string $key, mixed $default = null): mixed` | 读缓存，miss 返回 `$default` |
| `set` | `set(string $key, mixed $value, int $ttl = 0): void` | 写缓存；`$ttl` 秒，`<=0` 表示不过期 |
| `has` | `has(string $key): bool` | 判断 key 是否存在 |
| `delete` | `delete(string $key): void` | 删除 key |

---

## 五、使用要点与避坑

1. 底层驱动（Redis/Memory）由宿主实现决定，功能包不感知、不指定驱动。
2. `$ttl <= 0` 是**永不过期**，不是"立即过期"；临时数据务必显式给正数 ttl。
3. 缓存 key 建议带包短名前缀（如 `demo.xxx`），避免与其他包冲突。
4. 缓存只做加速，业务真相在数据库；读缓存后仍需容忍 miss 重建。
5. 持久化键值不要放缓存——用 dictionary 服务。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
