# LinHub 服务接入指南 · config 配置服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 config 配置服务的契约方法、接入方式与使用要点。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 config 一个服务；通用接入流程见总路由。

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
| 服务 id | `config` |
| 契约接口 | `linhub\host\services\config\Config` |
| 宿主实现 | `linhub\host\services\config\ConfigService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['config'], ...]

// 控制器/服务内
$cfg = HostServiceApi::getService($this->app, 'config', 'demo');
$timeout = $cfg->get('demo.request_timeout', 30);
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `get` | `get(string $key, mixed $default = null): mixed` | 读指定 key 配置；不存在返回 `$default` |
| `has` | `has(string $key): bool` | 判断配置是否存在 |
| `package` | `package(string $packageShort): array` | 读某功能包命名空间下全部配置 |

---

## 五、使用要点与避坑

1. **只读服务**：配置写入/管理归宿主平台，功能包只能读，不能写。
2. 包内设置项持久化不要用 config——用 **dictionary** 数据字典服务（可写、带包命名空间隔离）。
3. 读不到时给业务安全的 `$default`，不要假设配置一定存在。
4. `package($short)` 传包短名拿该包命名空间全量配置，一般传本包 short。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
