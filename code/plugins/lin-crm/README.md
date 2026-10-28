# 林客 CRM 功能包（lin-crm-pack）

> 林工矩阵 · 林枢功能包（方案 A）。命名空间 `Lg\Pack\Crm`，表前缀 `lg_lin-crm_`，事件主题前缀 `lg.crm.`。
> 依据：《林客_CRM开发需求文档_v1.0》《林客_产品需求清单_v1.0》及配套五件套（lin-crm/ 目录）。

## 1. 目录结构

```
lin-crm-pack/
├── manifest.php          # 接入声明（平台约束/依赖/资源/迁移/服务/订阅）
├── permissions.php       # 28 个权限点（crm:资源:操作 三段式）
├── menus.php             # 8 顶级 / 23 项菜单（含跟单看板）
├── migrations/           # 幂等 SQL 迁移（宿主迁移引擎执行）
│   ├── 001_tables_core.sql        # customer / contact / lead
│   ├── 002_tables_opportunity.sql # opportunity / opportunity_member
│   ├── 003_tables_contract.sql    # contract / contract_change / receivable_plan / receivable_record
│   ├── 004_tables_common.sql      # follow_up / notify_log / audit_log
│   ├── 005_tables_tool.sql        # pool_rule / attachment / dict（回收站已托管平台 recyclebin 服务，007 移除自建表）
│   ├── 006_seed.sql               # 字典种子 + 公海规则默认行（INSERT IGNORE 幂等）
│   ├── 007_drop_recycle.sql       # 移除自建回收站表（改用平台 recyclebin 服务）
│   └── 008_add_order_tracking.sql # follow_up.biz_type 扩枚举 order（跟单记录复用跟进表）
├── route/crm.php         # 模块路由（ThinkPHP 8）
├── src/                  # Lg\Pack\Crm（composer psr-4 映射 src/）
│   ├── Config/config.php       # 12 个租户级配置默认值
│   ├── CrmEvent.php            # 事件主题常量（lg.crm. 前缀 6 个发布主题）
│   ├── Model/                  # CrmModel 基类 + Customer/Lead/Opportunity/Contract
│   ├── Service/
│   │   ├── CodeService.php             # KH/XS/SJ/HT 编码生成
│   │   └── CustomerQueryService.php    # 对外服务 crm.customer.query
│   ├── Controller/             # Customer/Lead/Opportunity/Contract/Order/Report/Setting 七控制器
│   │   └── OrderController.php # 跟单看板（A：林销订单履约只读视图，客户名称弱关联；B：跟单记录+停滞预警）
│   └── Listener/TradeOrderStatusListener.php  # lg.trade.order.status 订阅（幂等预留）
├── view/                 # ThinkPHP View 骨架 + Vue3(workdesktop-ai) 接管交互
└── static/crm/css/app.css # 本包静态资源（安装时发布到 public/lgg/crm/）
```

## 2. 安装步骤（自行安装）

1. **放置包**：将 `lin-crm-pack/` 复制到宿主功能包目录（如 `pack/lin-crm/`），确认目录名与宿主安装器要求一致。
2. **自动加载**：在宿主 composer.json 追加并执行 `composer dump-autoload`：
   ```json
   "autoload": { "psr-4": { "Lg\\Pack\\Crm\\": "pack/lin-crm/src/" } }
   ```
3. **安装包**：宿主管理后台 → 功能包管理 → 安装"林客 CRM 功能包"。安装器将：
   - 校验 manifest（平台约束 host>=1.0,<2.0 / php>=8.0 / db 5.5）与依赖冲突（16 张 `lg_lin-crm_` 表名检测）；
   - 执行 `migrations/` 幂等 SQL（可重复执行；漏跑可重跑补偿）；
   - 登记 28 权限点（`permissions.php`）与 8 顶级菜单（`menus.php`）；
   - 注册对外服务 `crm.customer.query` 与订阅 `lg.trade.order.status`；
   - 发布静态资源到 `public/lgg/crm/`，运行缓存写入 `runtime/pack/crm/`。
4. **验证安装**（REL-02 三类验证口径）：
   - 权限：宿主权限中心可见 26 个 `crm:*` 权限点；
   - 菜单：导航出现"工作台/客户管理/线索管理/商机管理/合同管理/跟单看板/报表中心/系统设置"；
   - 迁移：租户库出现 16 张 `lg_lin-crm_` 表 + `lg_lin-crm_dict` 种子行 + `lg_lin-crm_pool_rule` 默认行；
   - 回退：卸载包 → 服务注销、菜单移除（表数据保留，卸载不清库）。

## 3. 实现覆盖说明

| 范围 | 状态 |
|------|------|
| M1 主线（客户档案/查重/公海私海/线索转化/跟进/行权限/审计/回收站/导入） | 可运行（控制器含完整业务逻辑骨架，撞单/软删/留痕红线已落地） |
| M2（商机阶段/合同审批/回款） | 控制器主链路已实现；前端页面为骨架，按 FE 清单补齐交互细节 |
| M3（报表四口径/林销联动） | 报表接口已实现 PHP 侧聚合（MySQL 5.5 无窗口函数）；林销订阅 handler 为幂等预留（⛔ OI-001 契约签署前不启用处理逻辑，见 Listener 注释） |
| 前端 20 页 | layout + 7 个代表页可用（View 渲染 + `window.__INITIAL_DATA__` + `createReadyApp` + 统一请求层）；其余页面按《林客_前端开发任务清单》§1 同模式补齐 |

## 4. 关键约定（开发必读）

- **红线**：RL-1 客户唯一（名称唯一索引 + 查重拦截）；RL-2 事实留痕不可改（回款/跟进只增改状态不改金额日期）；RL-3 软删进回收站 30 天（快照托管平台 recyclebin 服务 lg_mgt_recycle_bin，namespace=lin-crm，不自建表）；RL-4 后端双重鉴权（功能权限 + 行权限四档，见 `BaseController::scope()`）；RL-5 事件幂等 + 林销弱依赖降级。
- **状态枚举**（与需求清单附录 G 逐字一致）：线索 `unassigned/assigned/following/converted/invalid/recycled`；客户池 `public_pool/private_pool`；商机 `open/won/lost` × 阶段 `initial/confirm/proposal/negotiate`；合同 `draft/auditing/effective/settled/terminated`。
- **MySQL 5.5 禁用项**：CTE / 窗口函数 / JSON 类型 / 生成列 / utf8mb4_0900；单索引 ≤ 767 字节；`created_at` 为 TIMESTAMP（5.5 每表仅一列可用 CURRENT_TIMESTAMP 默认值），`updated_at/deleted_at` 由应用层维护。
- **配置项**（租户作用域，`src/Config/config.php` 默认值）：保护期 30 天、私海上限 50、释放时点 02:00、线索超期 7 天、赢率 10/30/50/70/100、停滞 14 天、合同审批开、回款提醒 T-3/T-1/D+1、脱敏开、回收站 30 天、导入批次 1000、报表缓存 300s。
