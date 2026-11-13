# LinHub 服务接入指南 · llmgateway LLM 网关服务 V1.2

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-03 01:30:00
> **当前版本**：V_1.2
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 llmgateway LLM 网关服务的契约方法、caller 焊死规则与异常处理清单与 manifest 数据随包导出配置。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 llmgateway 一个服务；provider 与模型实例配置见 .env LLM_* 配置块与后台 LLM 网关管理页。

---

## 一、文档大纲

1. 服务速览
2. 接入方式（caller 焊死）
3. 契约方法速查
4. 调用示例（chat / stream / embed）
5. 异常处理清单
6. 数据随包导出与导入（manifest 配置）
7. 使用要点与避坑

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `llmgateway` |
| 服务类型 | 功能服务（functional） |
| 数据随包 | 支持（配置类 seed / 日志类 all·range，通常仅 seed，见「数据随包导出与导入」节） |
| 契约接口 | `linhub\host\services\llmgateway\LlmGateway` |
| 宿主实现 | `linhub\host\services\llmgateway\LlmGatewayService`（注入实例经 `BoundLlmGateway` 装饰器包装） |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式（caller 焊死）

```php
// manifest.php
'services' => ['requires' => ['llmgateway' => '^1.0'], ...]

// 必须传包 id（第三参数）——拿到的实例已被 BoundLlmGateway 焊死 caller=pkg.demo
$llm = HostServiceApi::getService($this->app, 'llmgateway', 'demo');
```

**caller 解析规则（V1.1）**：
- 经 `HostServiceApi::getService($app, 'llmgateway', '<short>')` 拿到的实例焊死 `caller=pkg.<short>`，调用时**无需也不应再传 caller**（显式传会被覆盖）；
- 两者都为空（平台裸调用）→ 抛 `InvalidArgumentException`；
- 漏传包 id 会被焊死成 `pkg.unknown`，审计/配额归属失真。

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `chat` | `chat(ChatRequest $request): ChatResponse` | 同步对话 |
| `chatStream` | `chatStream(ChatRequest $request): \Generator` | 流式对话；每个元素 `{delta, finish_reason, usage}` |
| `embed` | `embed(EmbedRequest $request): EmbedResponse` | 向量嵌入 |
| `listModels` | `listModels(?string $capability = null): array` | 列可用模型实例；`$capability`：`chat`/`chat_stream`/`embedding`，null=全部 |
| `getModel` | `getModel(string $code): ?array` | 查单个模型详情，不存在返回 null |

请求/响应对象：`ChatRequest` / `ChatResponse` / `EmbedRequest` / `EmbedResponse`（同命名空间）。

---

## 五、调用示例（chat / stream / embed）

```php
use linhub\host\services\llmgateway\ChatRequest;

// 同步对话
$resp = $llm->chat(new ChatRequest([...]));
$answer = $resp->...;

// 流式对话
foreach ($llm->chatStream($req) as $chunk) {
    // $chunk['delta'] 增量文本
}

// 向量嵌入
$emb = $llm->embed(new EmbedRequest([...]));
```

---

## 六、异常处理清单（必须 catch）

| 异常 | 触发场景 |
| --- | --- |
| `LlmQuotaExceededException` | 配额超限 |
| `LlmModelUnavailableException` | 模型不存在或未启用 |
| `LlmProviderException` | provider 调用失败 |
| `InvalidArgumentException` | caller/messages 非法 |

不要让以上异常冒泡成 500——catch 后转业务响应（如降级文案、重试提示）。

---

## 七、数据随包导出与导入（manifest 配置）

本服务支持「服务数据随包导出与导入」机制：打包期按 manifest 的 `data` 段把本包相关数据导出为包内 `data/llmgateway.json`，安装/升级时回灌平台库。

**随包数据分两类，manifest 按 scope 选择打包的数据类型**：

- **配置类**：模型实例（平台全局）+ 本包调用方配额——包的运行依赖，跨环境迁移必须带走；
- **日志类**：本包调用日志（仅审计元数据）——运行态数据，体量大且通常无需随包。

本服务属**运行态服务**：未在 `manifest.data.services` 中显式声明时，打包默认不参与导出（防止误打包大体量调用日志）。**通常只需打包配置类数据即可（`scope => 'seed'`）**；日志类仅在确有留档/迁移需要时再按时间窗选取。

manifest 配置（manifest.php）：

```php
'data' => [
    'enable'  => true,                      // 缺省 true；置 false 整包不随带任何服务数据
    'services' => [
        'llmgateway' => ['scope' => 'seed'],            // 推荐：仅配置类（模型实例 + 本包配额）
        // 'llmgateway' => ['scope' => 'all'],          // 配置类 + 本包全部调用日志
        // 'llmgateway' => ['scope' => 'range',         // 仅日志类：时间窗内调用日志
        //     'range' => ['from' => '2026-08-01', 'to' => '2026-10-31']],   // 缺省近 90 天
    ],
],
```

**scope 与数据类型对照**：

| scope | 配置类（模型实例 + 本包配额） | 日志类（调用日志，仅审计元数据） |
| --- | --- | --- |
| `seed`（推荐） | 导出 | — |
| `all` | 导出 | 本包全部调用日志 |
| `range` | — | 时间窗内调用日志（`range.from` / `range.to`，Y-m-d，缺省近 90 天） |

**导入与卸载语义**：
- 安装/升级导入默认 `merge`（模型按 code 覆盖更新、配额按 caller+model 覆盖更新、日志按 call_id 补录）；`replace` 先清本包配额与日志再整批写入（模型实例不受 replace 影响）。
- 调用方（caller）归属强制为本包短名，数据行内伪造 caller 字段无效。
- 卸载时仅当本服务在 `manifest.data.services` 中显式声明才清理包名下配额与日志，未声明则保留；模型实例永远不清理。

**脱敏边界**：调用日志的 prompt/completion 正文一律不导出（导入后置空）；模型 API Key 在 env 中配置，本就不在导出范围。

---

## 八、使用要点与避坑

1. **必传包 id**：`getService($this->app, 'llmgateway', '<short>')`，漏传归属失真（见避坑清单第 3 条）。
2. `listModels($capability)` 供功能包页面下拉选择模型，不要硬编码模型 code。
3. 总开关 env `LLM_GATEWAY_ENABLED=0` 时所有 chat/embed 抛 `LlmProviderException`——功能包须容错。
4. 配额双维度（平台/包）由后台管理，功能包不要自己记账。
