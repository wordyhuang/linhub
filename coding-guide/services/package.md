# LinHub 服务接入指南 · package 功能包探测服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 package 功能包探测服务的契约方法与跨包协作探测模式。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 package 一个服务；屏蔽 data/plugin-state.json 状态文件细节。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 状态语义与跨包协作模式
5. 使用要点与避坑
6. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `package` |
| 契约接口 | `linhub\host\services\package\PackageProbe` |
| 宿主实现 | `linhub\host\services\package\PackageProbeService` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['package'], ...]

$probe = HostServiceApi::getService($this->app, 'package', 'demo');

if ($probe->isEnabled('lgg/pay')) { ... }   // 跨包协作先探测再调用
$snap = $probe->snapshot('lgg/pay');
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `isInstalled` | `isInstalled(string $name): bool` | 是否已安装（ENABLED/DISABLED 均算；ABSENT/DELETED 不算） |
| `isEnabled` | `isEnabled(string $name): bool` | 是否已启用（仅 ENABLED 为 true） |
| `versionOf` | `versionOf(string $name): string` | 已装包版本号；未装或已删除返回空串 |
| `snapshot` | `snapshot(string $name): array` | 状态快照 `{installed, enabled, state, version, short}` |
| `listInstalled` | `listInstalled(): array` | 全部已装包版本映射 `name => version`（与 DependencyResolver 同口径） |

---

## 五、状态语义与跨包协作模式

**状态语义**（与 `LifecycleState` 对齐）：
- `ABSENT`：无记录；`ENABLED`：已安装并启用；`DISABLED`：已安装但停用（仍算 installed=true）；`DELETED`：已删除残留（不算 installed）。

**跨包协作模式**：
- 安装期硬约束：manifest `dependencies` 声明；
- 运行期软探测：调对方路由/事件前先 `isEnabled()`，未启用走降级分支。

---

## 六、使用要点与避坑

1. **任何方法不抛异常**；未安装的包按 `installed=false / state=ABSENT / version=''` 处理，无需 try/catch。
2. `$name` 传包完整名（如 `lgg/pay`），不是短名。
3. `isEnabled` 与 `isInstalled` 语义不同：停用的包 installed=true 但 enabled=false，跨包调用应以 `isEnabled` 为准。
4. 只读服务：不提供安装/停用操作，那宿主生命周期管理的事。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
