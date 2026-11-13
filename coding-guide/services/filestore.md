# LinHub 服务接入指南 · filestore 文件存储服务（仅接口）V1.2

> **文档创建时间**：2026-10-24 10:00:00
> **最后修改时间**：2026-11-03 01:00:00
> **当前版本**：V_1.2
> **适用对象**：功能包（插件）开发者 / 智能体
> **核心目标**：说明 filestore 文件存储服务当前为「仅接口预留」状态，暂不可注入，并给出契约预告。
> **文档简略描述**：本文是《08_平台服务接入指引》的子路由；filestore 尚无宿主内置实现，现阶段功能包不要声明依赖。

---

## 一、文档大纲

1. 服务速览
2. 当前状态：仅接口，不可注入
3. 数据随包导出与导入（不适用）
4. 契约预告
5. 替代方案

---

## 二、服务速览

| 项 | 值 |
| --- | --- |
| 服务 id | `filestore` |
| 服务类型 | 托管服务（hosted，仅接口预留） |
| 数据随包 | 不适用（服务尚未实现，无落库数据、未声明 data_driver，见「数据随包导出与导入」节） |
| 契约接口 | `linhub\host\services\filestore\FileStore` |
| 宿主实现 | 无（`impl = null`） |
| 版本 | 1.0.0 |
| 状态 | **仅接口**，注入时抛「暂无宿主内置实现」 |

---

## 三、当前状态：仅接口，不可注入

- services.php 已登记契约，但宿主无内置实现。
- manifest `requires` 写了 `filestore` 可通过 M006 安装期校验，但**运行时注入抛 RuntimeException**。
- **现阶段不要写进 requires**。

---

## 四、数据随包导出与导入（不适用）

- **本服务当前不适用数据随包导出与导入**：参与资格由 services.php 的 `data_driver` 声明决定而非服务类型；filestore 无宿主实现、无落库数据、未声明驱动，自然不在打包范围内。
- **manifest 不可声明本服务**：`data.services` 写入 `filestore`（无论开关取值）会因未注册 `data_driver` 被 `ManifestValidator` 拒绝（**M015**），阻止打包与安装。
- **将来接入**：平台补齐宿主实现并声明数据驱动后，按 attachment 等托管服务口径在本文补齐 manifest 配置与 scope 语义。

---

## 五、契约预告

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `put` | `put(string $path, string $content): string` | 写文件，返回可访问相对路径 |
| `get` | `get(string $path): string` | 读文件；不存在抛 `\InvalidArgumentException` |
| `exists` | `exists(string $path): bool` | 判断文件是否存在 |
| `delete` | `delete(string $path): void` | 删除文件 |

物理存储（本地/OSS）由宿主实现，路径以包短名隔离。

---

## 六、替代方案

文件托管需求现阶段用 **attachment 附件托管服务**（已实现）：Token 存取 + 公开/私有鉴权 + 统一下载入口，见 [attachment.md](attachment.md)。
