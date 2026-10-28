# LinHub 服务接入指南 · attachment 附件托管服务 V1.0

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-10-24 10:00:00
> **当前版本**：V_1.0
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 attachment 附件托管服务的契约方法、Token 存取模式与鉴权规则。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 attachment 一个服务；存储驱动配置（env）与默认图兜底见 .env 的 ATTACH_* 配置块。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 核心模式：Token 存取与鉴权
5. 使用要点与避坑
6. 版本变化记录

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `attachment` |
| 契约接口 | `linhub\host\services\attachment\Attachment` |
| 宿主实现 | `linhub\host\services\attachment\AttachmentService`（本地/七牛驱动由 StorageFactory 按 env 决定） |
| 版本 | 1.0.0 |
| 状态 | 已实现，可注入 |

---

## 三、接入方式

```php
// manifest.php
'services' => ['requires' => ['attachment'], ...]

$att = HostServiceApi::getService($this->app, 'attachment', 'demo');

// 上传：得随机 Token 与统一访问 URL
$meta = $att->store('demo', $fileContent, 'report.pdf', ['is_public' => false]);
$downloadUrl = $att->url($meta['token']);
```

---

## 四、契约方法速查

### 功能包常用

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `store` | `store(string $ownerPackage, string $content, string $originName, array $meta = []): array` | 托管附件内容，返回元数据（含 `token`、`url`）。`$meta` 可选：`is_public`/`uploader_id`/`uploader_name`/`expire_days`（0=永久）/`mime` |
| `fetch` | `fetch(string $token, ?Identity $viewer = null): array` | 凭 Token 提取，返回 `{content, mime, name, attachment, fallback}`；公开附件 `$viewer` 可传 null |
| `delete` | `delete(string $token, ?Identity $viewer = null, bool $asAdmin = false): void` | 删除：管理员或上传者本人 |
| `url` | `url(string $token): string` | 生成统一访问 URL（公开/私有同入口，权限在 fetch 裁决） |
| `findByToken` | `findByToken(string $token): ?array` | 按 Token 取元数据，不存在返回 null |

### 管理端专用（功能包一般不用）

`paginate` / `stats` / `configState` / `testDriver` / `setFallbackImage` / `resetFallbackImage` / `fallbackImage` / `fallbackToken` / `saveSettings` / `clean` / `cleanRecords`。

---

## 五、核心模式：Token 存取与鉴权

- 调用方只面对 **Token 与本接口**，不感知物理存储（本地/七牛由 env `ATTACH_DRIVER` 工厂决定）。
- **公开附件**：免登录走 `/attachment/download?token=`。
- **私有附件**：fetch 按 `is_public` + 登录身份裁决；无权抛 `AttachmentDenied`；非图片附件不存在抛 `AttachmentNotFound`。
- 图片缺失/删除/过期走默认图兜底（env `ATTACH_FALLBACK_IMAGE`）。

---

## 六、使用要点与避坑

1. `store()` 的 `$ownerPackage` 传本包短名，归属错误会影响管理端归属展示与清理。
2. 私有附件不要把 URL 直接暴露给无权用户——统一走 `/attachment/download` 入口鉴权。
3. `expire_days` 传 0 为永久；过期附件会被清理任务回收，重要附件注意续期策略。
4. `fetch` 的两个异常（`AttachmentDenied`/`AttachmentNotFound`）必须 catch，转成业务响应而非 500。
5. 二进制内容直接传 `$content` 字符串；大文件先读入再传（平台不做流式）。

---

## 版本变化记录

- **V1.0（2026-10-24）**：初版。从 08 总路由拆分独立成文。
