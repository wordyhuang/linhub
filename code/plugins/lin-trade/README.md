# 林销 · 进销存功能包（lin/lin-trade）

> 林工矩阵之「林销」P0 功能包。面向小微企业"第一套数字化工具"，管理者买/卖/库存三件事。
>
> 版本：1.0.0（P0 首批）
> 依据 PRD：《林工_林销_进销存产品需求_v1.0.md》
> 依据协议：《林工_基础平台_功能包协议规范_v1.2/1.3》

## 1. 包是什么

作为林枢（LinHub）宿主的可安装功能包，提供：

- **商品管理**：分类 / 计量单位 / 商品档案 / 停用启用
- **采购管理**：采购订单草稿-提交-入库，状态机驱动
- **销售管理**：销售订单草稿-提交-出库（扣减库存，负库存红线）
- **库存管理**：实时库存 / 库存流水 / 盘点（盈亏调整）/ 仓库调拨 / 低库存预警
- **报表统计**：库存总览 / 采购销售汇总 / 出入库趋势 / 呆滞库存

## 2. 目录结构

```
code/plugins/lin-trade/
├── manifest.php                包声明（权限/菜单/服务/资源/迁移）
├── composer.json               PSR-4 自动加载
├── permissions.php             权限清单（13 项）
├── menus.php                   菜单（1 顶级 + 5 子菜单）
├── route.php                   路由声明
├── migrations/1.0.0/           建表迁移（16 张表，up/down 成对）
├── resources/config/trade.php  租户级配置默认值（5 项）
└── src/
    ├── Package.php             包入口（服务注册 + 生命周期钩子）
    ├── model/                  16 个模型实体 + BaseModel
    ├── Service/                业务服务层
    │   ├── InventoryService    库存核心（provides: trade.inventory）
    │   ├── ProductService      商品档案
    │   ├── PurchaseService     采购订单
    │   ├── SaleService         销售订单
    │   ├── StocktakeService    盘点
    │   ├── TransferService     调拨
    │   └── ReportService       报表统计
    ├── Support/TradeEvents.php 事件发布帮助类
    ├── subscriber/Subscribers.php 跨包订阅处理器（预留）
    └── controller/             控制器（商品/采购/销售/库存/报表）
```

## 3. 三个隔离前缀（协议必守）

| 类别 | 前缀 | 说明 |
|------|------|------|
| 命名空间 | `Lg\Pack\Trade\` | 包内 PHP 类统一在此空间 |
| 数据库表 | `lg_lin-trade_` | 全部 16 张表 |
| 事件主题 | `lg.trade.` | 本包发布的事件（见第 6 节） |

> 说明：PRD 中表名为逻辑名（如 `purchase_order`），实际建表按框架协议统一加 `lg_lin-trade_` 前缀；
> 由于宿主 DB 未配置表前缀，Model 用完整表名（`protected $table = 'lg_lin-trade_xxx'`）。

## 4. 权限清单（13 项）

编码三段式 `trade:资源:操作`，见 `permissions.php`：

| 资源 | 权限码 |
|------|--------|
| 商品 | trade:product:view / create / edit / delete |
| 采购 | trade:purchase:create / inbound / return |
| 销售 | trade:sale:create / outbound / return |
| 库存 | trade:inventory:view / stocktake / transfer |
| 报表 | trade:report:view |

## 5. 服务依赖

`manifest.services.requires`：

- `config` — 租户配置读写
- `logging` — 日志
- `id` — 主键/编号生成（授权 PRD 的 `id_generator`，宿主实际契约 id 为 `id`）
- `messaging` — 事件发布/订阅总线

对外提供服务 `trade.inventory`（`InventoryService`），供林造/林备等跨产品调用库存查询与变动。

## 6. 业务对象状态机

**采购/销售订单**（`lg_lin-trade_purchase_order` / `lg_lin-trade_sale_order` 的 `status` 字段）：

```
draft(草稿) ──提交──> submitted(已提交)
submitted ──入库/出库──> partial(部分) ──> done(完成) ──> closed(关闭)
draft / submitted ──作废(无出入库行为)──> voided(作废)
```

- 作废仅在 `draft`、`submitted` 且**无任何出入库行为**时允许，并记录 `void_reason`。
- 完成 = 全部明细行 `received_qty >= quantity`（采购）或 `outbound_qty >= quantity`（销售）。
- **退货（已实现）**：采购退货扣库存（`return_out`）、销售退货回补库存（`return_in`），并累加明细 `returned_qty`；可退货量 ≤ 已收（出）量 − 已退量。

**盘点单**（`lg_lin-trade_stocktake`）：

```
taking(盘点中) ──确认──> confirmed(已确认) ──调整──> adjusted(已调整)
```

**调拨单**（`lg_lin-trade_transfer`）：

```
submitted(已提交) ──完成──> done(已完成)
```

## 7. 事件发布（lg.trade.*）与跨包联动（PRD 5.3）

### 本包发布（顶事件，主题见 `TradeEvents.php`）

| 主题 | 语义 |
|------|------|
| `lg.trade.product.created` | 商品建档 |
| `lg.trade.purchase.inbound` | 采购入库完成 |
| `lg.trade.purchase_order.audit` | 采购审核（提交）通过 |
| `lg.trade.sale_order.created` | 销售订单创建 |
| `lg.trade.sale.outbound` | 销售出库发生 |
| `lg.trade.sale.order.done` | 销售订单全部出库 |
| `lg.trade.inventory.changed` | 通用库存变动（携带明细） |

### 跨包订阅（消费其它产品事件）

按 PRD 5.3 应订阅：

- `lg.crm.deal.closed` → 转销售订单
- `lg.production.issue.outbound` → 生产下料/领料出库
- `lg.production.issue.return` → 生产退料入库
- `lg.quality.iqc.passed` → 来料质检通过（辅助采购入库）
- `lg.quality.iqc.failed` → 来料质检不合格（触发采购退货）

**已知限制（重要）**：宿主 `ManifestValidator` 的 **M008** 强制 `subscribes.topic` 必须以本包前缀 `lg.trade.` 开头，因此上述**外部主题无法写入 `manifest.subscribes`**，否则安装会被校验拦截。本包处理方式：

1. `manifest.subscribes = []`，保证可安装；
2. 订阅处理逻辑已写好在 `src/subscriber/Subscribers.php`，并在 `Package::boot()` 预留装配入口（默认关闭）；
3. 待宿主 M008 放行外部主题订阅（或需运行期 `messaging->subscribe`），启用 `Package::registerSubscribers(true)` 即可。

## 8. 业务边界与异常处理

- **负库存红线（P0）**：`InventoryService::apply()` 在出库类变动时锁定库存行并校验 `after >= 0`，不足即抛异常、整体回滚，绝不产生负库存。
- **库存变动双写**：每次变动必须同时更新 `lg_lin-trade_inventory` 与 `lg_lin-trade_inventory_log`，且在同一事务内，保证"实时库存"与"流水账"一致可溯源。
- **金额/数量校验**：DECIMAL(18,4) 存储；入库/出库数量不得超过明细剩余量；金额按 `数量 × 单价` 计算。
- **单据不可篡改**：完成后不可改写行；作废需记录原因；流水 `ref_id` 关联来源单据可追溯。
- **并发**：库存行 `lock(true)` 加行锁，避免并发超卖。

## 9. 配置项（租户级，见 `resources/config/trade.php`）

| 配置键 | 默认 | 说明 |
|--------|------|------|
| `trade.product_no.rule` | `auto_prefix` | 商品编码规则：自动前缀 `SP` / 手动录入 |
| `trade.order.allow_negative_stock` | `false` | 销售订单库存不足是否允许负库存（P0 默认禁止） |
| `trade.stocktake.lock_inventory` | `false` | 盘点期间是否冻结出入库 |
| `trade.order.auto_convert_crm` | `true` | 林客商机成交是否自动转销售订单 |
| `trade.warehouse.default` | `总仓` | 默认仓库名称 |

## 10. 迁移

`migrations/1.0.0/001_create_trade_tables.{up,down}.sql` 成对提供：

- `.up.sql` 建 16 张表（InnoDB / utf8mb4），含唯一键、业务索引、库存唯一键 `uk_prod_wh`；
- `.down.sql` 逆序 DROP。

## 11. 已实现 / 二期预留

**P0 已实现**：商品、采购、销售、库存（查询/盘点/调拨/预警）、报表、采购/销售退货。

**P1（二期）预留**：多单位换算、批次、序列号、条码打印；生产下料/退料、林检来料合格/不合格等跨包事件联动处理（订阅主题已对齐 PRD 5.3，待宿主 M008 放开外部主题后激活）。