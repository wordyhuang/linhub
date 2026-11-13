# LinHub 服务接入指南 · translation 翻译服务 V1.4

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-10 12:30:00
> **当前版本**：V_1.4
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 translation 翻译服务的契约方法、回退链与包间隔离规则与 manifest 数据随包导出配置。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 translation 一个服务；包内 `translations/` 种子文件随安装导入/升级覆盖/卸载清除。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 回退链与占位符
5. 包间读写隔离与种子机制
6. 数据随包导出与导入（manifest 配置）
7. 使用要点与避坑
8. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `translation` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 支持（seed / all，见「数据随包导出与导入」节） |
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

## 七、数据随包导出与导入（manifest 配置）

本服务支持「服务数据随包导出与导入」机制：打包期按 manifest 的 `data` 段把本包名下词条导出为包内 `data/translation.json`，安装/升级时回灌平台库。

与包内 `translations/` 种子机制的关系：种子文件走安装期自动导入/升级覆盖/卸载清除（见上文「包间读写隔离与种子机制」节），管首次装机的词条基线；manifest data 走打包导出/回灌，管把运行期维护的词条打包带走。两条通道相互独立。

manifest 配置（manifest.php）：

```php
'data' => [
    'enable'  => true,                      // 缺省 true；置 false 整包不随带任何服务数据
    'services' => [
        'translation' => ['scope' => 'seed'],   // 只带公开词条基线（安全默认）
        // 'translation' => ['scope' => 'all'],  // 全部词条
    ],
],
```

**scope 语义**：
- `seed`：仅导出公开词条（is_public=1）；
- `all`：导出本包名下全部词条（namespace+key+lang 维度）。

**导入与卸载语义**：
- 安装/升级导入默认 `merge`（按 namespace+dict_key+lang 覆盖更新）；`replace` 先清本包旧词条再整批写入。
- 导入行归属强制为本包短名，数据行内伪造归属字段无效。
- 卸载时仅当本服务在 `manifest.data.services` 中显式声明才清理包名下词条，未声明则保留（防误删）。
- 单服务导出上限 50000 行，超出截断（`data/index.json` 标记 truncated）。

**脱敏边界**：无特殊脱敏。

---

## 八、使用要点与避坑

1. **与 dictionary 的区别**：翻译有 lang 维度，读路径是「按 (namespace, lang) 拉全量 map + 回退合并」；dictionary 是键值持久化。数据值别名展示（status='pending' → 「待支付」）与页面文案多语言化用 translation。
2. **中文语境必须输出中文（硬性建议）**：凡会展示给用户的英文状态类内容——枚举值、业务状态码（如 `pending`/`paid`/`shipped`）——都必须翻译成中文后再展示（列表用 `map`/`translateBatch`，单条用 `translate`），禁止把英文原文直接暴露在页面、审批快照、站内信等用户可见处。**只翻译展示层**：存储、接口返回、程序判断仍用英文原值（翻译词条按 `状态键.status键` 形态组织，如 `status.pending`）。**快照类一次性落库的内容平台不翻译**：审批 payload 等快照由所属服务**原样输出**，其中文化是提交方（包侧开发者）在提交前自行完成的责任，本服务只是可选工具之一。**平台内置服务自身状态不走本服务**：审批单/节点状态等由引擎固定集合独占的状态，其中文化由该服务编码内固定字典映射完成（如审批中心前端 `STATUS_TEXT`/`NODE_TEXT`），不占翻译词条。
3. 列表页渲染用 `translateBatch`/`map`，不要在循环里逐条 `translate`（N+1）。
4. lang 维度写全：`put` 是按 (key, lang) 一行一条，多语言要逐 lang 写。
5. miss 返回 key 原文是特性不是 bug——前端展示兜底，但要及时补词条。

---

## 九、版本变化记录

- **V1.4（2026-11-10）**：明确**快照类一次性落库内容平台不翻译**（§八.2）——审批 payload 等快照由所属服务原样输出，中文化是提交方开发者在提交前自行完成的责任，本服务仅为可选工具；配合 approval 指引 V1.17 的「原样输出」边界。
- **V1.3（2026-11-10）**：明确**平台内置服务自身状态不走本服务**（§八.2）——审批单/节点状态等引擎固定集合的状态由该服务编码内固定字典翻译（如审批中心 `STATUS_TEXT`），不占翻译词条；本服务面向包侧业务状态值/枚举与页面文案。
- **V1.2（2026-11-10）**：新增「中文语境必须输出中文」硬性建议（§八.2）——凡展示给用户的英文状态类内容（单据/节点状态、枚举值、状态码）必须接入翻译服务转中文后展示，禁止英文原文直出用户可见处；明确「只翻译展示层」边界：存储/接口/程序判断仍用英文原值，审批快照等一次性落库内容须入快照前完成翻译。

