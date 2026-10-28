# LinHub 服务接入指南 · dictionary 数据字典服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 dictionary 数据字典服务的契约方法、包命名空间隔离规则与读写边界。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 dictionary 一个服务；多语言词条场景应改用 translation 翻译服务。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 读写边界：命名空间隔离
5. 使用要点与避坑
6. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `dictionary` |
| 契约接口 | `linhub\host\services\dictionary\Dictionary` |
| 宿主实现 | `linhub\host\services\dictionary\DictionaryService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['dictionary'], ...]

$dict = HostServiceApi::getService($this->app, 'dictionary', 'demo');

// 写本包一条字典（key 包内唯一）
$dict->put('demo', 'theme.color', ['primary' => '#409EFF'], ['is_public' => true]);

// 读（本包全量可读；他包仅公开可读）
$v = $dict->get('demo', 'demo', 'theme.color', null);
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `put` | `put(string $package, string $key, mixed $value, array $meta = []): array` | 保存（新增/更新）本包一条字典；`$value` 支持标量与任意 JSON 结构；`$meta` 可选 `is_public`/`remark` |
| `get` | `get(string $package, string $namespace, string $key, mixed $default = null, bool $asAdmin = false): mixed` | 读一条；无权/不存在返回 `$default` |
| `remove` | `remove(string $package, string $namespace, string $key, bool $asAdmin = false): bool` | 删除本包一条；超管可删任意包 |
| `search` | `search(string $package, array $filter = [], int $page = 1, int $size = 20, bool $asAdmin = false): array` | 检索；filter 支持 `namespace`/`keyword`/`is_public` |
| `entry` | `entry(string $package, string $namespace, string $key, bool $asAdmin = false): ?array` | 单条原始条目（含 value 原文与类型）；无权返回 null |
| `stats` | `stats(): array` | 按命名空间统计条目数（平台看板用） |

---

## 五、读写边界：命名空间隔离

- 定位规则：**「功能包命名空间 + 业务 key」**一条字典数据。
- 功能包视角（DD-8）：只能**写本包**；可读本包全部 + 他包 `is_public` 数据。
- 管理端视角（DD-9）：超管可跨包检索，默认只读（`asAdmin` 可读写私有他包）。
- 越权写抛 `DictionaryDenied`。

---

## 六、使用要点与避坑

1. 适合包内设置项、枚举映射等键值持久化；**跨包共享配置须显式 `is_public => true`**。
2. `$package` 参数传本包短名（托管方身份）；`$namespace` 是目标命名空间（读他包时填对方 short）。
3. 多语言/带 lang 维度的词条不要用 dictionary——用 **translation** 翻译服务。
4. 配置只读场景（读平台配置）不要用 dictionary——用 config 服务。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
