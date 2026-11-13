# LinHub 服务接入指南 · attachment 附件托管服务 V1.1

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-03 00:10:00
> **当前版本**：V_1.1
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：给出 attachment 附件托管服务的契约方法、Token 存取模式与鉴权规则与 manifest 数据随包导出配置。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由，只讲 attachment 一个服务；存储驱动配置（env）与默认图兜底见 .env 的 ATTACH_* 配置块。

---

## 一、文档大纲

1. 服务速览
2. 接入方式
3. 契约方法速查
4. 核心模式：Token 存取与鉴权
5. 数据随包导出与导入（manifest 配置）
6. 使用要点与避坑

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `attachment` |
| 服务类型 | 托管服务（hosted） |
| 数据随包 | 支持（seed / all，见「数据随包导出与导入」节） |
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

## 六、数据随包导出与导入（manifest 配置）

本服务支持「服务数据随包导出与导入」机制：打包期按 manifest 的 `data` 段把本包名下附件元数据导出为包内 `data/attachment.json`，安装/升级时回灌平台库。

manifest 配置（manifest.php）：

```php
'data' => [
    'enable'  => true,                      // 缺省 true；置 false 整包不随带任何服务数据
    'services' => [
        'attachment' => ['scope' => 'seed'],    // 只带公开附件基线（安全默认）
        // 'attachment' => ['scope' => 'all'],  // 全部附件元数据
    ],
],
```

**scope 语义**：
- `seed`：仅导出公开附件（is_public=1）的元数据；
- `all`：导出本包名下全部附件元数据（含私有）。

**导入与卸载语义**：
- 安装/升级导入默认 `merge`（按 token 覆盖更新）；`replace` 先清本包旧附件元数据再整批写入。
- 导入行归属强制为本包短名，数据行内伪造归属字段无效。
- 仅导出元数据，物理文件不随包：导入后 `storage_path` 置空、`token` 保留（包内既有引用不失效），文件内容由目标环境沿用原存储或重新上传。
- 卸载时仅当本服务在 `manifest.data.services` 中显式声明才清理包名下附件元数据，未声明则保留（防误删）。
- 单服务导出上限 50000 行，超出截断（`data/index.json` 标记 truncated）。

**脱敏边界**：导出行不含 `uploader_id` / `uploader_name` / `storage_path`。

---

## 七、使用要点与避坑

1. `store()` 的 `$ownerPackage` 传本包短名，归属错误会影响管理端归属展示与清理。
2. 私有附件不要把 URL 直接暴露给无权用户——统一走 `/attachment/download` 入口鉴权。
3. `expire_days` 传 0 为永久；过期附件会被清理任务回收，重要附件注意续期策略。
4. `fetch` 的两个异常（`AttachmentDenied`/`AttachmentNotFound`）必须 catch，转成业务响应而非 500。
5. 二进制内容直接传 `$content` 字符串；大文件先读入再传（平台不做流式）。
