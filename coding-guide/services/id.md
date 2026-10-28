# LinHub 服务接入指南 · id ID 生成器服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 id ID 生成器服务的契约方法、接入方式与使用要点。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 id 一个服务；通用接入流程见总路由。

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
| 服务 id | `id` |
| 契约接口 | `linhub\host\services\id\IdGenerator` |
| 宿主实现 | `linhub\host\services\id\SequentialIdGenerator` |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['id'], ...]

// 控制器/服务内
$idgen = HostServiceApi::getService($this->app, 'id', 'demo');
$bizId = $idgen->generate();       // 字符串全局唯一 ID
$seqId = $idgen->generateInt();    // 数值型 ID
```

---

## 四、契约方法速查

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `generate` | `generate(): string` | 生成一个全局唯一字符串 ID（雪花/有序） |
| `generateInt` | `generateInt(): int` | 生成一个长整型数字 ID（适用于数值主键） |

---

## 五、使用要点与避坑

1. 保证进程内与跨进程唯一，功能包业务主键用它，**不要**自己写 `uniqid()`/`mt_rand()` 拼主键。
2. 分布式插入频繁的表优先用 `generateInt()` 作数值主键；需要防枚举的对外标识用 `generate()`。
3. ID 生成器只管唯一性，不含业务含义；不要从 ID 里解析业务信息。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
