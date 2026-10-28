# 林枢 / LinHub — 插件化中后台宿主基础平台

> **文档创建时间**：2026-10-07 22:20:00
> **最后修改时间**：2026-10-27 23:47:00
> **当前版本**：V1.9
> **适用对象**：产品、架构、后端、前端、测试、运维、智能体
> **核心目标**：为"林工"产品矩阵（林造/林质/林艺/林备/林客/林销/林账/林员）及未来所有自研软件，提供一个插件化中后台宿主基础平台，实现应用的逻辑分区、独立开发、按功能包打包安装与版本/依赖/资源/通信的统一管理。
> **文档简略描述**：本文档是仓库的总入口说明，描述项目定位、目录结构、文档导航与开发推进方式，供人与智能体快速上手。S0–S8 全部完成，UAIS 真实协议、MySQL 真实落库、Redis Stream 真实接入都已联调通过，宿主 GUI View 层已打通；前端已按需求采用 workdesktop-ai（Vue3 + ElementPlus）UMD 直引方式重构登录页与工作台，并经 Playwright 真机端到端验证；中后台 8 个管理页 GUI（角色/权限组/权限项/用户/日志审计/归档备份/界面替换/schema 差异看板）补建完成并通过门禁矩阵与 Playwright 真机回归，内页组件已统一迁移为 workdesktop-ai 写法（api-0~api-10，Playwright 真机回归全部 OK）；已完成平台服务治理改造，新增编码指引手册，平台公共服务扩至 16 个（基础四件套/附件托管/数据字典/登录状态/站内消息/消息事件/LLM 网关/功能包探测/权限查询/翻译/回收站托管）。

---

## 一、项目是什么

LinHub 是一个**插件化中后台宿主平台**。核心思路是：平台本身只提供宿主能力，各业务林字系列应用以「功能包（plugin）」的形式打包、声明、安装，由平台统一管理版本、依赖、数据库迁移、界面定制与权限。

---

## 二、目录结构

| 目录 | 用途 | 说明 |
| --- | --- | --- |
| `coding-guide/` | AI 编码指引手册 | 总纲 + 架构/后端/前端/功能包/数据/测试/规范 7 份专项指引，AI 开发第一必读 |
| `document/` | 方案与需求讨论产物 | 需求报告、任务清单、协议规范、流程图，均带版本号 |
| `code/` | 所有代码相关内容 | `code/host/` 是 ThinkPHP 宿主工程，`code/dist/` 为部署目录，**只读，由部署程序生成，禁止手动修改** |
| `log/` | 执行代码产生的日志 | |
| `output/` | 执行代码产生的文件 | Logo 等设计产物 |
| `temp/` | 临时生成的文件 | 测试脚本与临时数据，提交 PR 应清理 |
| `readme.md` | 项目总说明 | 本文档 |
| `memory.md` | 智能体常记忆与经验沉淀 | skill / eval / rule / hooks / vault [`*`] |
| `code-router.md` | 代码路由表 | 追踪「需求编号 / 文档定义 → 代码实现点位」的映射 |

> [*] `memory.md` 与 `code-router.md` 实际存放于根目录，与需求编号体系（A–R、T1–T10）对接。

---

## 三、文档导航（进入代码前的权威依据）

### 入门路径

**开发推进顺序**：
1. 先读本文档（总览项目定位与现状）
2. 读 `coding-guide/00_总纲_开发导航.md`（开发指引入口）
3. 对照 `document/林枢_基础平台需求报告_v1.0.md` 看功能需求
4. 对照 `document/林枢_基础平台任务清单_v1.0.md` 分阶段推进

### 核心文档索引

| 文档 | 路径 | 作用 |
| --- | --- | --- |
| **编码指引（AI 开发手册）** | `coding-guide/` | 开发约定、编码范式、避坑指南，AI 开发第一必读 |
| 基础平台需求报告 | `document/林枢_基础平台需求报告_v1.0.md` | 核心功能需求、非功能、约束、开放点 T1–T10 |
| 协议规范 | `document/林工_基础平台_功能包协议规范_v1.2.md` | manifest 格式、schema 迁移、错误码 M 序列 |
| 任务清单 | `document/林工_基础平台任务清单_v1.0.md` | S0–S8 阶段拆解，编码的推进依据 |
| 流程图 | `document/林工_基础平台_流程图_v1.0.html` | 安装/依赖/定制/访问控制等流程可视化 |
| 功能包开发指南 | `document/林工_基础平台_功能包开发指南_v1.2.md` | 平台边界、目录结构、manifest/声明文件接入写法、生命周期全链路，供功能包开发者独立上手 |
| 平台公共服务需求报告 | `document/林枢_平台公共服务需求报告_v1.0.md` | 附件托管服务、数据字典服务、登录状态服务、站内消息服务四个公共服务的需求 |

### 改造方案

- `林枢_基础平台_多应用与多模块改造方案_v1.1.md`：多应用映射改造方案
- `林枢_基础平台_服务治理改造方案_v1.0.md`：平台服务清单化改造方案

---

## 四、当前实现状态（S0–S8 + 服务治理已完成）

宿主核心代码位于 `code/host/linhub/host/src`，按子系统分目录组织：

| 子系统 | 能力 | 验证结果 |
| --- | --- | --- |
| S1 manifest | manifest 校验（M001–M011）、资源清单、打包、脚手架、完整性 | 42 PASS |
| S2 lifecycle | 状态机、依赖/平台校验、备份、安装/升级/回退/启停/删除 | 42 PASS |
| S3 migration | 数据库网关、迁移版本/执行、Schema 快照与差异、备份 | 38 PASS + 真实 MySQL 18 PASS（`temp/t3_mysql_live_test.php`） |
| S4 integration | 权限/菜单/工作台/界面替换四类接入点注册 | 34 PASS |
| S5 rbac | 登录鉴权、角色/权限组/用户、最终权限计算、审计、归档 | 64 PASS |
| S6 communication | 宿主服务 API、日志流水线、事件 Broker（内存/Redis Stream）、服务网关、订阅 | 50 PASS + 真实 Redis 11 PASS（`temp/t6_redis_live_test.php`） |
| S7 security | 超管管控、高危操作保护、审计贯通、权限一致性、路由守卫分流 | 45 PASS |
| S8 e2e | 示例功能包、端到端全链路验收 | 54 PASS |
| G9 管理页 | 中后台管理页 GUI + 门禁矩阵 + Playwright 回归 | PASS=28 FAIL=0 |
| G10 服务治理 | 平台服务清单化、垂直目录改造、新增 4 个公共服务 | 开发完成 |

**平台公共服务（已纳入服务清单）**：
1. **logging/config/cache/id**：基础服务（日志/配置/缓存/ID 生成）
2. **attachment**：附件托管服务——本地/七牛工厂、公开/私有鉴权、默认兜底、过期孤儿清理
3. **dictionary**：数据字典服务——键值对托管、功能包隔离、公开/私有、超管全局检索
4. **loginstate**：登录状态服务——供功能包获取当前用户信息与角色标签
5. **inbox**：站内消息服务——支持向用户/角色/用户组发消息、红点计数、已读管理
6. **messaging**：消息/事件服务——共享进程内事件总线，包间发布/订阅与宿主生命周期广播
7. **filestore/scheduler**：文件存储/任务调度（预留接口，暂不可注入）
8. **llmgateway**：LLM 网关服务——OpenAI 兼容协议、chat/embed、配额与审计
9. **package**：功能包探测服务——安装/启用状态、版本号查询，只读无异常
10. **auth**：账号与权限查询服务——用户/角色/权限只读清单与 ensureRole 幂等接入
11. **translation**：翻译服务——多语言词条托管、回退链与占位符、包间隔离
12. **recyclebin**：回收站托管服务——任意结构数据快照入站、恢复/彻底删除、保留期与配额、包卸载孤儿态保留与重装接管

**端到端链路**（`temp/s8_e2e_test.php`）：打包 → 安装（S3 迁移 + S4 接入）→ 权限/菜单/界面接入 → 通信 → 升级 → 回退 → 删除，全程 S7 守卫与审计参与，54 PASS。

> 第三方服务对接状态：
> - **UAIS**：已完成真实协议对接并线上联调（`temp/uais_live_test.php`，11 PASS）：AppKey/AppSecret 换取应用 JWT（缓存），`auth/login` 用户名密码登录返回用户 tags，超管判定：用户 tag 含 `LinHub SuperAdministrator`。
> - **MySQL**：真实落库验证通过（`temp/t3_mysql_live_test.php`，18 PASS），连真库跑迁移 up/down/事务/回滚/幂等全部 OK。
> - **Redis**：Stream 真实接入验证通过（`temp/t6_redis_live_test.php`，11 PASS），Predis 发布订阅读回 payload 正常。

---

## 五、技术栈与关键约定（摘要）

### 技术栈

| 组件 | 版本与说明 |
| --- | --- |
| 框架 | ThinkPHP 8（多应用模式） |
| PHP | 必须 8.2+，本机固定路径 `D:\BtSoft\php\82\php.exe` |
| 前端 | workdesktop-ai 1.2.4（Vue3 + ElementPlus）UMD 直引，前后端不分离 |
| 数据库 | MySQL 8+，平台系统库 `lin_hub`，表前缀 `lg_mgt_`/`linservice_`（平台） / `lg_<short>_`（功能包） |
| 缓存/消息 | Redis 6+，`predis/predis`，事件 Broker 双实现 |
| 身份认证 | UAIS，超管标签 `LinHub SuperAdministrator` |

### 关键约定

> 完整约定见 `coding-guide/` 指引。

- 语言：日常沟通用中文；技术报错用原文。
- 文件统一 `UTF-8` 保存，中文页保留 `<meta charset="UTF-8">`。
- 目录分类存放：代码 `code/`、部署 `code/dist`（只读）、日志 `log/`、产物 `output/`、临时 `temp/`、方案 `document/`。
- 文档头必须标注创建/修改时间、版本、适用对象、核心目标、简略描述；末尾标注版本变化；主要变更后文件名追加 `_v[版本]` 存档。
- 安全红线：密钥走环境变量，不入源码；`.env` 进忽略规则。
- 开发采用 TDD：先定验证标准 → 编码 → 测试 → 修复 → 复验。

---

## 六、前端技术说明

宿主前端按需求采用 **workdesktop-ai**（自研 Vue3 组件库，基于 ElementPlus）搭建，但保持**前后端不分离 + Form POST + UAIS 登录 + 浏览器 302 跟随**的架构完全不变。

- **接入方式**：UMD `<script>` 直引嵌入 ThinkPHP View 模板，挂载到 `window.WorkDesktop`。加载顺序固定：`vue.global.prod.js → element-plus/index.full.min.js → icons/index.iife.min.js → workdesktop-ai/index.umd.js`。静态资源统一放 `code/host/public/static/vendor/`。
- **模板写法**：页面用 `<script type="text/x-template">` 字符串模板 + `template: '#id'`。不要用 `app.mount('#app')` 读取 `#app` 内联 DOM 做模板——in-DOM 模板解析会在 el-form 之后静默截断，生产版 Vue 无报错。
- **门禁协议**：JSON 响应 `{code, data, message}`，`code>=0` 即成功；JSON 接口用 `gateForJson()`（未登录→401、普通→403），成功 `jsonOk()` / 失败 `jsonFail()`。
- 详见 `coding-guide/03_前端编码指引.md`。

---

## 七、中后台管理页现状

在 `linhub/admin/src/controller` 下补齐中后台管理页（统一继承 `AdminController`，每个 action 首行调用 `gate()`/`gateForJson()` 门禁校验）：

| 页面 | 控制器 | 能力 |
| --- | --- | --- |
| 角色管理 | `Role` | 新增/删除角色（type/name），联动权限组的权限项 |
| 权限组管理 | `Group` | 新增权限组 + 抽屉内勾选权限项 |
| 权限项管理 | `Permission` | 平台权限点列表 |
| 用户管理 | `User` | 新增用户、分配角色、预览最终权限 |
| 日志审计 | `Audit` | 操作日志 / 管控轨迹 / 迁移审计三 tab |
| 归档备份 | `Backup` | 立即归档、编辑策略、还原 |
| 界面替换 | `Uireplace` | 选择生效 / 回退平台默认页 |
| schema 差异 | `Schema` | 选定功能包比对真实库结构与快照差异 |
| 平台服务治理 | `PlatformService` | 平台服务列表、版本、变更历史查看 |

全部页面已统一迁移为 workdesktop-ai 组件写法（`wd-data-grid` / `wd-dialog-button` / `WD.request`），并补充对应 JSON 接口，经 Playwright 真机回归全部 OK。

---

## 版本变化记录

- **V1.9（2026-10-27）**：平台公共服务清单补全至 16 个（此前停留在 5 项，缺 messaging/filestore/scheduler/llmgateway/package/auth/translation），新增 **recyclebin 回收站托管服务**（平台第 16 个公共服务：任意结构数据快照入站、恢复/彻底删除、保留期与配额、包间全私有隔离、卸载孤儿态保留与重装接管）；简略描述同步 16 服务口径。
- **V1.8（2026-10-12）**：补充编码指引 `coding-guide/` 入口导航；整理平台公共服务现状（附件托管/数据字典/登录状态/站内消息）；修正目录结构表述（功能包落地根 `code/plugins/`）；梳理子系统结构与验证结果；版本更新。
- **V1.7（2026-10-08 20:40）**：admin 管理页内页组件统一迁移为 workdesktop-ai 写法（api-0~api-10）。新增「四·补３」说明节：8 个管理页从旧式（服务端注入 wdState + postForm PRG）迁移为 `wd-data-grid`/`wd-dialog-button`/`wd-popconfirm-button`/`WD.request`+本地 el-table，并为各模块新增 JSON 接口，沿用 `{code,data,message}` 协议 + `gateForJson`/`jsonOk`/`jsonFail` + 审计留痕。迁移真机回归全部 OK，仍满足 G9 门禁矩阵。
- **V1.6（2026-10-08 19:20）**：中后台管理页 GUI 补建完成（G0–G9）。新增「四·补２」说明节：角色/权限组/权限项/用户/日志审计/归档备份/界面替换/schema 差异 8 个管理页（`app/admin` 应用、继承 AdminController、每个 action 首行 `gate()` 门禁、`postForm()` PRG 写操作、全程审计留痕）。G9 整体回归通过：门禁矩阵 `g9_gate_matrix_test.php` PASS=3 FAIL=0（10 控制器×三身份）、Playwright 真机 `g9_2_e2e.py` PASS=28 FAIL=0、存量 S8 回归 `s8_e2e_test.php` PASS=54 FAIL=0 + 工作台/功能包页登录后 200 无回归。
- **V1.5（2026-10-08 14:05）**：按需求用 workdesktop-ai（Vue3 + ElementPlus）重构宿主前端。login.html / dashboard.html 改 UMD 直引 + x-template 字符串模板，`Home::dashboard()` 注入 `wdState`（user/menuGroups/slots）驱动 `<wd-station>` 渲染；登录沿用原生 Form POST + UAIS 302；`url_html_suffix` 改 `''` 修复带 `.html` 后缀 404。Playwright `temp/wd_e2e2.py` 真机端到端验证通过（守卫 302 / 登录渲染 / 表单登录 / WdStation 工作台 / 退出 302，无 console/page 错误）。新增「四·补」前端说明节。
- **V1.4（2026-10-08 12:30）**：UAIS 成功登录真实验证收尾，`temp/uais_live_test.php` 8 → 11 PASS，真实账号 wordyhuang（id=86，tags=[LinHub SuperAdministrator]）登录→tags→超管准入全通过；修复 `/auth/login` 用 `data.token`、下游读 `access_token` 的字段契约 bug；遗留待办去除成功登录项，仅剩 open/* 后台授权。
- **V1.3（2026-10-08 12:00）**：MySQL 真实落库（18 PASS、`temp/t3_mysql_live_test.php`）、Redis Stream 真实接入（11 PASS、`temp/t6_redis_live_test.php`）、宿主 GUI View 层打通（24 PASS + HTTP 冒烟）；readme/code-router/memory 三表同步 V1.3，遗留待办收口。
- **V1.2（2026-10-08 10:05）**：UAIS 真实协议对接完成（UaisClient 应用 JWT 缓存、真实用户名密码登录、auth/me Bearer 解析），线上联调 8 PASS；同步说明 open/* 接口授权前提。
- **V1.1（2026-10-08 09:30）**：S0–S8 全部开发完成。新增「当前实现状态」一节（各阶段目录、能力与验证 PASS 数），文档导航补入《功能包开发指南》，说明端到端验收结论与外部服务占位情况。
- **V1.0（2026-10-07 22:20）**：仓库初始化。创建目录骨架（code/log/output/temp）、本项目说明 readme、经验沉淀 memory、代码路由表 code-router。
