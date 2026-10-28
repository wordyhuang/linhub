# LinHub 服务接入指南 · llmgateway LLM 网关服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 llmgateway LLM 网关服务的契约方法、caller 焊死规则与异常处理清单。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 llmgateway 一个服务；provider 与模型实例配置见 .env LLM_* 配置块与后台 LLM 网关管理页。

---

## 一、文档大纲

1. 服务速览
2. 接入方式（caller 焊死）
3. 契约方法速查
4. 调用示例（chat / stream / embed）
5. 异常处理清单
6. 使用要点与避坑
7. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `llmgateway` |
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

## 七、使用要点与避坑

1. **必传包 id**：`getService($this->app, 'llmgateway', '<short>')`，漏传归属失真（见避坑清单第 3 条）。
2. `listModels($capability)` 供功能包页面下拉选择模型，不要硬编码模型 code。
3. 总开关 env `LLM_GATEWAY_ENABLED=0` 时所有 chat/embed 抛 `LlmProviderException`——功能包须容错。
4. 配额双维度（平台/包）由后台管理，功能包不要自己记账。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
