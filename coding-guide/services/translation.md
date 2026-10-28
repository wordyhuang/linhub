# LinHub 服务接入指南 · translation 翻译服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 translation 翻译服务的契约方法、回退链与包间隔离规则。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 translation 一个服务；包内 `translations/` 种子文件随安装导入/升级覆盖/卸载清除。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 回退链与占位符
5. 包间读写隔离与种子机制
6. 使用要点与避坑
7. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `translation` |
| 契约接口 | `linhub\host\services\translation\Translator` |
| 宿主实现 | `linhub\host\services\translation\TranslatorService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['translation'], ...]

$tr = HostServiceApi::getService($this->app, 'translation', 'demo');

// 单条翻译：status='pending' → 「待支付」
$text = $tr->translate(null, 'demo', 'status.pending', 'zh-CN');

// 批量翻译（一次 IO 消灭 N+1）
$map = $tr->translateBatch(null, 'demo', ['status.pending', 'status.paid'], 'zh-CN');
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `translate` | `translate(?string $package, string $namespace, string $key, string $lang, array $params = [], string $fallbackLang = 'zh-CN'): string` | 单条翻译；回退链 + `{name}` 占位符替换；miss 时返回 key 原文 |
| `translateBatch` | `translateBatch(?string $package, string $namespace, array $keys, string $lang, string $fallbackLang = 'zh-CN'): array` | 批量翻译，返回 `key => 译文` map（含回退） |
| `map` | `map(?string $package, string $namespace, string $lang): array` | 可见范围内本语言全量词条 `key => value`（60s 进程缓存） |
| `put` | `put(?string $package, string $key, string $lang, string $value, array $meta = [], bool $asAdmin = false): array` | 写词条（仅本包命名空间；超管可写任意）；`$meta` 支持 `is_public` |
| `remove` | `remove(?string $package, string $key, string $lang, bool $asAdmin = false): bool` | 删词条（本包自查自删；他包仅 asAdmin） |
| `search` | `search(array $filter, int $page = 1, int $size = 20): array` | 后台检索；filter 支持 `namespace`/`lang`/`keyword`。仅超管 |

---

## 五、回退链与占位符

- **回退链**：`lang` → `fallbackLang`（默认 `zh-CN`）→ 返回 key 原文——页面永不露空值。
- **占位符**：`{name}` 形式，`$params` 传值替换；未提供的占位符保留原样。
- **lang 显式传参**：不猜不嗅探，一律显式传 BCP-47 简码（如 `zh-CN` / `en-US`）。

---

## 六、包间读写隔离与种子机制

- **读**：本包 namespace 全量可读；他包仅 `is_public=1` 可读；`host` 命名空间任何包可读。
- **写**：仅允许 caller 写自己包的 namespace；超管 `asAdmin` 可写任意。
- **`$package = null`**：消费侧默认形态，静态回溯调用链判定 caller 所属功能包。
- **种子机制**：包内 `translations/` 目录种子随**安装导入、升级全量覆盖、卸载清除**——升级后包内 seed 是词条唯一权威。

---

## 七、使用要点与避坑

1. **与 dictionary 的区别**：翻译有 lang 维度，读路径是「按 (namespace, lang) 拉全量 map + 回退合并」；dictionary 是键值持久化。数据值别名展示（status='pending' → 「待支付」）与页面文案多语言化用 translation。
2. 列表页渲染用 `translateBatch`/`map`，不要在循环里逐条 `translate`（N+1）。
3. lang 维度写全：`put` 是按 (key, lang) 一行一条，多语言要逐 lang 写。
4. miss 返回 key 原文是特性不是 bug——前端展示兜底，但要及时补词条。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
