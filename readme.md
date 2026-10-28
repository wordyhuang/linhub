# 林枢 LinHub

插件化中后台宿主基础平台。平台自身只提供宿主能力，业务应用以「功能包（plugin）」的形式打包、安装、升级、卸载，由平台统一管理版本、依赖、数据库迁移、菜单/权限/界面接入与通信。

## 核心特性

- **功能包协议**：manifest 声明与校验（M001–M011）、资源清单、打包、脚手架生成、完整性校验。
- **生命周期管理**：安装 / 升级 / 回退 / 启用停用 / 删除，含依赖与平台版本校验、自动备份与恢复。
- **数据库迁移**：迁移版本管理、up/down、事务执行、Schema 快照与差异比对、迁移审计。
- **四类接入点**：权限、菜单、工作台插槽、界面替换（功能包页面可接管平台默认页）。
- **RBAC 与安全**：角色 / 权限组 / 权限项 / 用户、最终权限计算、路由守卫、高危操作保护、操作审计。
- **事件与服务**：事件 Broker 提供进程内与 Redis Stream 双实现；平台公共服务可注入功能包使用。
- **中后台管理**：功能包管理、用户与权限、日志审计、归档备份、界面替换、Schema 差异、服务治理等页面开箱可用。
- **前后端不分离**：服务端渲染 + JSON 接口，前端以 UMD 直引方式挂载 workdesktop-ai 组件。

**平台公共服务（17 个）**：`logging`、`config`、`cache`、`id`、`filestore`、`scheduler`、`messaging`、`attachment`、`dictionary`、`loginstate`、`inbox`、`llmgateway`、`package`、`auth`、`translation`、`recyclebin`、`approval`。

## 技术栈

| 模块      | 说明                                                                          |
| ------- | --------------------------------------------------------------------------- |
| 框架      | ThinkPHP 8（多应用模式）                                                           |
| PHP     | 8.2+                                                                        |
| 数据库     | MySQL 8+，平台库 `lin_hub`，表前缀 `lg_mgt_` / `linservice_`（平台）、`lg_<short>_`（功能包） |
| 缓存 / 消息 | Redis 6+（`predis/predis`），未配置时事件总线走进程内实现                                    |
| 前端      | workdesktop-ai（Vue 3 + Element Plus）UMD 直引                                  |
| 身份认证    | UAIS 统一身份认证，超管标识为标签 `LinHub SuperAdministrator`                             |

## 目录结构

```
.
├─ code/
│  ├─ host/                 # 宿主工程（ThinkPHP 8 多应用）
│  │  ├─ app/               # 开发者代码：默认应用、中间件、控制器
│  │  ├─ linhub/            # 平台 Composer 库：capability（能力）/ host（内核）/ admin（中后台）
│  │  ├─ config/            # 应用配置（多应用映射、数据库、UAIS、日志等）
│  │  ├─ route/ public/     # 路由与 Web 入口（public/index.php）
│  │  ├─ data/              # 持久业务数据区与 JSON 状态文件
│  │  ├─ .example.env       # 环境变量模板（复制为 .env 后填写）
│  │  └─ think              # CLI 入口
│  └─ plugins/              # 功能包源码落地根（本地安装生成，不随仓库分发）
├─ coding-guide/            # 编码指引手册（开发第一必读）
├─ data/                    # 示例数据（lin-trade 测试数据 SQL）
├─ AGENTS.md                # 智能体与协作约定
├─ memory.md                # 开发经验沉淀
└─ readme.md                # 本文
```

> 功能包（`code/plugins/`）与打包暂存区（`code/host/package/`）属于本地安装与运行产物，不在仓库中分发；克隆后用平台「功能包管理」安装取得。

## 快速开始

**前置条件**：PHP 8.2+（需 `pdo_mysql`、`mbstring`、`fileinfo`、`openssl`、`curl` 扩展）、Composer、MySQL 8+；Redis 6+ 可选。

```bash
# 1. 安装依赖（平台库以 path repository 引入，改代码后需重生成自动加载）
cd code/host
composer install
composer dump-autoload

# 2. 准备环境变量：复制模板后填写 DB 连接等
cp .example.env .env    # Windows PowerShell: Copy-Item .example.env .env

# 3. 创建空库（表结构无需手工导入，见下方说明）
#    CREATE DATABASE lin_hub DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

# 4. 启动开发服务器
php think run -H 127.0.0.1 -p 8213
```

访问 `http://127.0.0.1:8213/` 即可进入平台。生产部署时将 Web 根目录指向 `code/host/public/` 即可。

**数据库初始化说明**

- 平台自带表（管理、服务、日志等）在首次访问时以 `CREATE TABLE IF NOT EXISTS` 幂等创建，无需手工建表。
- 功能包的建表脚本放在各自的 `migrations/{version}/*.up.sql`，随功能包安装/升级执行。

**必需补充的静态资源**

仓库不包含 `code/host/public/static/vendor/`（`vue`、`element-plus`、`icons`、`workdesktop-ai` 等 UMD 产物）。首次拉取后需按组件库的发布包放入该目录，否则页面无法正常渲染，加载顺序为：

```
vue.global.prod.js → element-plus/index.full.min.js → icons/index.iife.min.js → workdesktop-ai/index.umd.js
```

**登录**

登录走 UAIS 统一身份认证，需在 `.env` 填写 `UAIS_BASE_URL`、`UAIS_APP_KEY`、`UAIS_APP_SECRET`。未接入 UAIS 时，可用代码内置的演示身份（`linhub/host/src/support/HostBootstrap.php` 中的 `DEMO_ADMIN`）进行本地调试。

## 功能包开发

一个功能包落在 `code/plugins/{short}/`，由声明文件 + 迁移 + 代码 + 视图组成：

```
lin-trade/
├─ manifest.php      # 包声明：short、名称、版本、平台版本约束、依赖、资源
├─ menus.php         # 菜单接入
├─ permissions.php   # 权限点接入
├─ shortcuts.php     # 快捷方式接入（可选）
├─ workspace.php     # 工作台插槽接入（可选）
├─ route.php         # 路由
├─ migrations/       # 数据库迁移脚本（按版本分目录）
└─ src/              # Controller / Service / model / view
```

功能包通过平台「功能包管理」页面打包安装，或将目录包交由超管直接安装；安装过程由平台完成迁移执行、接入点注册、状态记录与备份。

## 文档

`coding-guide/` 是开发时的权威约定来源，改代码前请先读总纲。

| 文档                                        | 内容                                        |
| ----------------------------------------- | ----------------------------------------- |
| [总纲与开发导航](coding-guide/00_总纲_开发导航.md)     | 阅读路径、任务类型索引、核心铁律                          |
| [架构与目录约定](coding-guide/01_架构与目录约定.md)     | 整体架构、目录、Composer 库边界、多应用、命名空间             |
| [后端编码指引](coding-guide/02_后端编码指引.md)       | 控制器、门禁、JSON 协议、审计、服务注册、RBAC               |
| [前端编码指引](coding-guide/03_前端编码指引.md)       | workdesktop-ai 页面写法、组件清单、接口联调             |
| [功能包开发指引](coding-guide/04_功能包开发指引.md)     | manifest、声明文件、路由、生命周期                     |
| [数据与迁移指引](coding-guide/05_数据与迁移指引.md)     | 表前缀隔离、迁移脚本、Schema 快照                      |
| [测试与验证指引](coding-guide/06_测试与验证指引.md)     | TDD 流程、验证脚本、门禁                            |
| [文档提交与安全规范](coding-guide/07_文档提交与安全规范.md) | 文档格式、文件分类、提交约束、安全红线                       |
| [平台服务接入指引](coding-guide/08_平台服务接入指引.md)   | 服务清单与接入流程；各服务细节见 `coding-guide/services/` |

## 开发约定

- 文件统一使用 `UTF-8`；中文页面保留 `<meta charset="UTF-8">`。
- 密钥、令牌、账号凭证只走环境变量，`.env` 不入库；模板见 `code/host/.example.env`。
- 采用 TDD：先确定验证标准，再编码，跑通后复验。
- 提交需说明变更意图与范围，不提交本地配置与生成产物。

## 许可

`code/host` 基于 [ThinkPHP](https://www.thinkphp.cn/)（Apache-2.0）构建，相关许可见 [LICENSE.txt](code/host/LICENSE.txt)。

***

**文档信息**：版本 V2.0 · 最后修改 2026-10-28 · 适用对象：产品、架构、后端、前端、测试、运维与智能体

**版本变化**

- **V2.0（2026-10-28）**：重新整理为 GitHub 展示口径。移除内部验证流水账、临时脚本与本机绝对路径；目录结构只保留仓库实际内容；平台公共服务清单按服务注册表更新为 17 项（新增 approval）；补充克隆后的依赖与静态资源准备步骤。
- **V1.9（2026-10-27）**：补齐平台公共服务清单，新增回收站托管服务。
- **V1.0（2026-10-07）**：仓库初始化。

