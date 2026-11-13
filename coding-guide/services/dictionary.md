# LinHub 服务接入指南 · dictionary 数据字典服务 V1.2

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-03 00:10:00
> **当前版本**：V_1.2
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 dictionary 数据字典服务的契约方法、包命名空间隔离规则与读写边界与 manifest 数据随包导出配置。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 dictionary 一个服务；多语言词条场景应改用 translation 翻译服务。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 读写边界：命名空间隔离
5. 数据随包导出与导入（manifest 配置）
6. 使用要点与避坑

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `dictionary` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 支持（seed / all，见「数据随包导出与导入」节） |
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

## 六、数据随包导出与导入（manifest 配置）

本服务支持「服务数据随包导出与导入」机制：打包期按 manifest 的 `data` 段把本包名下字典项导出为包内 `data/dictionary.json`，安装/升级时回灌平台库。

manifest 配置（manifest.php）：

```php
'data' => [
    'enable'  => true,                      // 缺省 true；置 false 整包不随带任何服务数据
    'services' => [
        'dictionary' => ['scope' => 'seed'],    // 只带公开字典项基线（安全默认）
        // 'dictionary' => ['scope' => 'all'],  // 全部字典项（含私有）
    ],
],
```

**scope 语义**：
- `seed`：仅导出公开字典项（is_public=1）；
- `all`：导出本包名下全部字典项（含包私有）。

**导入与卸载语义**：
- 安装/升级导入默认 `merge`（按 namespace+dict_key 覆盖更新）；`replace` 先清本包旧字典项再整批写入。
- 导入行归属（namespace/owner_pkg）强制为本包短名，数据行内伪造归属字段无效。
- `value` 按 JSON 原样导出导入，无损，不做编解码。
- 卸载时仅当本服务在 `manifest.data.services` 中显式声明才清理包名下字典项，未声明则保留（防误删）。
- 单服务导出上限 50000 行，超出截断（`data/index.json` 标记 truncated）。

**脱敏边界**：无特殊脱敏。

---

## 七、使用要点与避坑

1. 适合包内设置项、枚举映射等键值持久化；**跨包共享配置须显式 `is_public => true`**。
2. **同系列配置用对象聚合，不要碎片化建键**：一组配置（同一系列）或一个配置页面的全部字段，优先作为**一个对象存在一个 key 的 `$value` 里**，如非必要不要为每个字段单独建 key：

   ```php
   // 推荐：一个 key 存整组配置
   $dict->put($pkg, 'theme', ['primary' => '#409EFF', 'layout' => 'dark'], ['is_public' => true]);

   // 不推荐：同一系列拆成一堆 key，键名膨胀，查看与检索都困难
   $dict->put($pkg, 'theme.primary', '#409EFF');
   $dict->put($pkg, 'theme.layout', 'dark');
   ```

   这样键名数量少，管理端检索、查看与整组读写都更方便；个别需要独立公开/独立变更的字段再拆出来单独建 key。
3. `$package` 参数传本包短名（托管方身份）；`$namespace` 是目标命名空间（读他包时填对方 short）。
4. 多语言/带 lang 维度的词条不要用 dictionary——用 **translation** 翻译服务。
5. 配置只读场景（读平台配置）不要用 dictionary——用 config 服务。
