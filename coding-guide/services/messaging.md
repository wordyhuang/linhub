# LinHub 服务接入指南 · messaging 消息/事件服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 messaging 消息/事件服务的契约方法、发布/订阅写法、主题命名规则与生命周期广播。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 messaging 一个服务；功能包事件订阅的 manifest 装配细节另见《04_功能包开发指引》第九节。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 发布与订阅写法
5. 主题命名（M008）与生命周期广播
6. 使用要点与避坑
7. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `messaging` |
| 契约接口 | `linhub\host\services\messaging\Messaging` |
| 宿主实现 | `linhub\host\services\messaging\MessagingService`（共享进程内事件总线 Broker） |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['messaging'], ...]

// 控制器/服务内
$messaging = HostServiceApi::getService($this->app, 'messaging', 'demo');
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `publish` | `publish(string $topic, array $payload = []): void` | 发布事件到指定主题 |
| `subscribe` | `subscribe(string $topic, string $handler): void` | 订阅主题；handler 需无参构造 + `handle(Event $event)` |

`Event` 类：`linhub\host\communication\broker\Event`，含 `topic/payload/occurredAt/source/id`。

---

## 五、发布与订阅写法

```php
// 发布本包业务事件（供其他包订阅；主题带本包前缀）
$messaging->publish('lg.demo.order.created', ['order_id' => 42]);

// 运行时动态订阅（权威路径是 manifest.subscribes 声明）
$messaging->subscribe('lg.platform.package.removed', DemoHandler::class);
```

**两条订阅装配路径**：
1. **权威**——manifest `subscribes` 声明，宿主 `PackageRouteLoader` 每请求汇总已启用包装配进共享总线（见 04 指引第九节）；
2. **互补**——`Package::boot()` 里主动 `subscribe`（幂等去重，两条路径不冲突）。

---

## 六、主题命名（M008）与生命周期广播

| 前缀 | 归属 | 合法性 |
| --- | --- | --- |
| `lg.<short>.*` | 本方/跨包业务事件 | 合法 |
| `lg.platform.*` | 宿主保留（生命周期广播） | 合法，只读不可发布 |
| 其他形状 | — | 安装被拦 |

宿主生命周期广播主题（自动发布，订阅即可感知平台包变更）：
- `lg.platform.package.installed`
- `lg.platform.package.upgraded`
- `lg.platform.package.removed`
- `lg.platform.package.packed`

---

## 七、使用要点与避坑

1. **handler 契约**：无参构造 + `handle(Event $event)`；不支持 `Class@method`。多主题共用一个 handler 类，在 `handle()` 内按 `$event->topic` 分发。
2. **投递语义**：`publish` 绝不因消费者失败抛错（宿主重试后吞掉）；发布方建议 try/catch 降级记日志。
3. **先落库后广播**：业务事件先持久化再 `publish`，防止订阅方拉不到数据。
4. 不要订阅/发布 `lg.platform.*` 的发布方向（宿主保留）；订阅监听可以。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
