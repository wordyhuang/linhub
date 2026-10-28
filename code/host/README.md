# LinHub 林枢 · 宿主工程（code/host）README V1.1

> **文档创建时间**：2026-10-12 10:30:00
> **最后修改时间**：2026-10-20 10:00:00
> **当前版本**：V_1.2
> **适用对象**：在本工程内开发的后端、前端、测试、运维与智能体
> **核心目标**：说明 `code/host/` 宿主工程（ThinkPHP 8 多应用）的定位、目录结构、运行方式与关键约定，作为进入本目录开发的第一份说明。
> **文档简略描述**：本文替代 ThinkPHP 脚手架自带的通用 README，面向 LinHub 项目实际结构：三层代码归属（app / linhub / 外部功能包落地目录）、环境准备与启动命令、平台内核模块与服务清单、目录导航、核心约定摘要及上级文档索引。

---

## 一、文档大纲

1. 工程定位
2. 技术栈与运行环境
3. 目录结构
4. 环境准备与启动
5. 平台内核与服务清单
6. 关键约定摘要
7. 相关文档导航
8. 版本变化记录

---

## 二、工程定位

`code/host/` 是 **LinHub 林枢·插件化中后台宿主基础平台** 的宿主工程根目录（ThinkPHP 8 项目）。平台本体为各"林字"业务应用提供宿主能力：功能包（plugin）的打包、安装、版本/依赖管理、数据库迁移、界面定制与权限控制。

代码组织遵循"平台库独立"治理：**开发者自己的代码留在 `app/`**，平台内核/能力/中后台分别独立为 Composer 库 `linhub/host`、`linhub/capability`、`linhub/admin`（物理目录 `linhub/` 下）；功能包源码不在本工程手工编写，由超管「功能包管理」GUI 安装时落地到上级 `code/plugins/{short}/`。

---

## 三、技术栈与运行环境

| 项 | 值 |
| --- | --- |
| 框架 | ThinkPHP 8（`topthink/framework` ^8.0，多应用 `think-multi-app`） |
| PHP | **8.2**（本机运行时 `D:\BtSoft\php\82\php.exe`；系统 PATH 的 php 为 7.2，不可用；composer 声明 `>=8.0`） |
| 数据库 | MySQL（平台系统库 `lin_hub`，utf8mb4） |
| 缓存/消息 | Redis（`predis/predis`），事件 Broker 提供内存 / Redis Stream 双实现 |
| 身份认证 | UAIS（`linhub/capability/auth`），超管判定 tag 含 `LinHub SuperAdministrator` |
| 前端 | workdesktop-ai 1.2.4（Vue3 + ElementPlus）UMD 直引，前后端不分离 |
| 多应用 | index 默认公开、admin 中后台（经 `config/app.php` 的 `app_map` 闭包映射到 `linhub/admin/src`） |
| 平台版本 | `linhub\host\Version::current()` = `0.1.0` |

---

## 四、目录结构

```
code/host/
├─ app/                    # 开发者自己的代码（唯一"自研"区）
│  ├─ index/               # 公开默认应用（controller/Attach、Notice + route）
│  ├─ middleware/          # HostSession 全局中间件
│  ├─ AppService.php       # 应用装配（boot 中启动 PackageRouteLoader 加载功能包路由）
│  ├─ service.php / provider.php / middleware.php / event.php
│  └─ BaseController.php / ExceptionHandle.php / Request.php / common.php
├─ linhub/                 # 平台库（独立 Composer 包，path repository 引入）
│  ├─ capability/          # 库1：基础能力（auth 身份：UAIS 客户端/身份接口，无容器耦合）
│  ├─ host/                # 库2：宿主内核（见第五节模块清单）
│  └─ admin/               # 库3：中后台应用（命名空间 app\admin，经 app_map 映射）
├─ config/                 # 应用配置（app.php 多应用映射、database.php、uais.php 等）
├─ route/                  # 根路由
├─ public/                 # Web 入口（index.php / router.php）与静态资源
│  └─ static/vendor/       # UMD 依赖：vue、element-plus、icons、workdesktop-ai
├─ runtime/                # 仅放可再生的运行时产物（缓存/日志/临时解压），可随时清理
├─ data/                   # 持久业务数据区（见下方说明，勿清空、勿入库、不随部署覆盖）
├─ extend/                 # PSR-0 扩展目录（暂空）
├─ vendor/                 # composer 依赖（含 linhub/* path 包）
├─ think                   # CLI 入口（php think ...）
├─ .env / .example.env     # 环境配置（DB_*、UAIS_*；.env 已忽略不入库）
├─ composer.json           # 根工程，path repository 引入 linhub/capability|host|admin
└─ README.md               # 本文
```

**runtime/ 主要内容（可再生产物，`think clear` 清理不会造成业务数据丢失）**：`log/*.log` 运行日志、`temp/`、`admin/log|temp`、`session/`（会话）、`platform-update/`（升级临时解压工作区）。

**data/ 持久数据区（`code/host/data/`，有状态业务数据的唯一归宿）**：`rbac/`（角色权限 JSON + 归档）、`log/trail.json`（审计留痕）、`log/audit.json`（操作日志）、`integration/registry.json`（菜单/权限集成注册）、`platform/setting.json`（站点设置）、`home/`（工作台定制与用户档案）、`attach/`（本地附件对象）、`plugin-state.json` / `plugin-review.json`（功能包状态与审核）、`plugin-staging/`（待安装暂存包）、`plugin-backups/`（安装升级备份）、`plugin-disabled/`（禁用包隔离）、`plugin-packout/`（打包产物）、`platform-backups/`（平台升级备份）、`platform-versions.json`（版本记录）。这些数据因独立于 `runtime/`，**不会被 `php think clear` 误清**，也不会被平台自升级覆盖。

**相关兄弟目录**：

| 目录 | 说明 |
| --- | --- |
| `code/plugins/` | 已安装功能包的**运行期落地根**，形如 `code/plugins/{short}/`，由「功能包管理」安装流程自动创建，无需手工维护 |
| `code/frontend/` | 前端依赖工程（`linhub-frontend`，依赖 workdesktop-ai 1.2.4 / element-plus / icons），UMD 产物供 View 直引 |

---

## 五、环境准备与启动

```powershell
# 1. 安装/更新依赖（平台库为 path repository）
composer install
composer dump-autoload        # 改动 linhub/* 或 composer.json 后必须重生成，否则类找不到 500

# 2. 环境配置：复制 .example.env 为 .env 并填真实值（DB_*、UAIS_*；.env 不入库）
#    Copy-Item .example.env .env

# 3. 语法检查（改动文件逐个跑，零失败）
D:\BtSoft\php\82\php.exe -l 目标文件.php

# 4. 启动开发服务器
D:\BtSoft\php\82\php.exe think run -H 127.0.0.1 -p 8213
```

**访问入口**：

- 根路径 `/` → 重定向 `/admin/home/dashboard`（中后台工作台）
- 登录页 `/admin/auth/login`（demo-admin 演示账号 / UAIS 真实账号）
- 功能包路由由各包 `route.php` 在启用期挂载，如 `/demo/record`（仅 state=enabled 的包可达）
- 公开服务如 `/attachment/download?token=`（免登录）

---

## 六、平台内核与服务清单

`linhub/host/src/` 主要子系统：

| 子目录 | 职责 |
| --- | --- |
| `support/` | `HostBootstrap` 管理页统一入口（当前用户、RBAC、审计、菜单、seed） |
| `services/` | 平台服务（清单化注册，唯一权威来源 `services/services.php`） |
| `bridge/ServiceInjector` | 按服务清单向功能包注入服务实例 |
| `communication/` | HostServiceApi、事件 Broker（内存/Redis Stream）、服务网关、订阅表、日志管道 |
| `integration/` | 功能包集成注册：菜单、权限、路由（PackageRouteLoader）、工作区、UI 替换 |
| `lifecycle/` | 功能包安装/升级/卸载/启停/回滚、依赖解析、审核存储、平台兼容校验 |
| `manifest/` | manifest 声明解析与校验、版本约束 |
| `migration/` | 迁移引擎、SchemaDiff 看板、前缀校验、备份 |
| `package/` | 功能包脚手架、打包、完整性校验 |
| `rbac/` | 角色（四类）、权限分组/可见性/计算、用户与 JSON 存储、归档备份 |
| `security/` | 门禁决策、路由守卫、高风险操作保护、权限一致性、审计留痕 |
| `console/` | 控制台命令（如附件孤儿清理） |
| `home/` | 工作台定制、用户档案 |
| `linservice/` | "林服务"接入存储 |

**平台服务清单（14 个，以 `services/services.php` 为准）**：

| 服务 id | 状态 | 说明 |
| --- | --- | --- |
| logging / config / cache / id | 已实现 | 日志、配置、缓存、顺序 ID 生成 |
| filestore / messaging / scheduler | 仅接口 | 文件存储、消息事件、任务调度（预留） |
| attachment | 已实现 | 附件托管：Token 存取、公开/私有鉴权、本地/七牛工厂、过期清理 |
| dictionary | 已实现 | 数据字典：功能包命名空间隔离、公开只读 |
| loginstate | 已实现 | 登录状态：当前用户/游客态、tags、角色快照 |
| inbox | 已实现 | 站内消息：按用户/角色/组投递并快照去重、已读管理 |
| llmgateway | 已实现 | LLM 网关：OpenAI 兼容协议、同步/流式 chat、embedding、双维配额、全量审计日志 |
| package | 已实现 | 功能包探测：已安装/已启用/版本号/清单，只读无异常 |
| auth | 已实现 | 账号与权限只读查询：登录快照、用户/角色/权限组/权限点清单与判定，只读无异常 |

> 新增平台服务的标准动作：建 `services/<id>/` 垂直目录 → 在 `services.php` 的 list/changelog 登记 → 由 ServiceInjector 自动注入。管理端治理页位于 admin 库（PlatformService / ServiceGovernance）。

---

## 七、关键约定摘要

1. **命名空间**：平台内核 `linhub\host\*`、能力 `linhub\capability\*`（全小写）；管理控制器 `app\admin\controller`；功能包 `Lg\Pack\<Short>\*`。
2. **多应用 URL**：一律显式应用前缀（`/admin/...`、`/index/...`），不依赖无前缀 `url()`。
3. **前端**：管理页统一 workdesktop-ai UMD（x-template + `template:'#id'`），JSON 协议 `{code,data,message}`、`code>=0` 成功。
4. **门禁与审计**：管理 action 首行 `gate()`/`gateForJson()`；写操作 `auditTrail()->log(...)` 留痕。
5. **表前缀隔离**：功能包 `lg_<short>_`；平台统一 `lg_mgt_`（服务/能力、消息、附件、字典、LLM、RBAC 等平台表均以 `lg_mgt_` 打头）。
6. **功能包生命周期**：absent → enabled ⇄ disabled；未审核包安装/升级返回 423；仅启用包挂载路由。
7. **TDD**：每阶段交付有 `temp/` 验证脚本，用 `D:\BtSoft\php\82\php.exe` 运行，以 `PASS=N FAIL=0` 为准。

> 完整约定见上级《编码指引》文件夹，勿凭直觉自行发挥。

---

## 八、相关文档导航

| 文档 | 路径（相对本目录） | 作用 |
| --- | --- | --- |
| 编码指引（AI 开发手册） | `../../coding-guide/` | 总纲 + 架构/后端/前端/功能包/数据/测试/规范 7 份指引 |
| 项目总说明 | `../../readme.md` | 平台定位、文档导航、S0–S8 现状 |
| 代码路由表 | `../../code-router.md` | 需求编号 → 代码点位映射（实现后回填） |
| 智能体记忆 | `../../memory.md` | [vault]/[skill]/[eval]/[rule]/[hooks] 经验沉淀 |
| 方案与协议文档 | `../../document/` | 需求报告、协议规范、功能包开发指南、改造方案 |

---

## 版本变化记录

- **V1.2（2026-10-20）**：统一命名规范并同步文档——
  ① 平台级数据库表前缀统一收敛为 `lg_mgt_`（原散布的 `linservice_`/`linmsg_`/`lg_llm_`/`rbac_` 前缀合并，见第七节约定与 `SqlPrefixValidator`）；
  ② 平台服务实现文件命名统一为「契约名 + Service」惯例，`LogService` 更名 `LoggingService`；
  ③ 平台服务清单由 11 个更新为 14 个（补充 llmgateway / package / auth）。
- **V1.1（2026-10-12）**：按工程实际结构校正与补充——① 明确 `code/plugins/` 为安装期自动创建的功能包落地根（非手工开发目录）；② 新增第六节"平台内核与服务清单"，列出 `linhub/host/src` 全部子系统及 11 个平台服务（补充 attachment/dictionary/loginstate/inbox/scheduler 与通信、控制台等新模块）；③ 补充 runtime 目录构成、访问入口与功能包生命周期约定；④ 技术栈补充 PHP composer 约束、双 Broker 实现与 workdesktop-ai 版本号。
- **V1.0（2026-10-12）**：初版。以 LinHub 实际结构重写宿主工程 README（替代 ThinkPHP 脚手架通用 README），覆盖工程定位、技术栈、目录结构、环境准备与启动、关键约定与文档导航。
