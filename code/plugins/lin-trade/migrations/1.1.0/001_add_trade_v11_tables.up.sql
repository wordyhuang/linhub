-- 林销（进销存）功能包 建表迁移 v1.1.0
-- PRD《林工_林销_进销存产品需求_v1.1》 V_1.1 新增实体
-- 引擎 InnoDB / 字符集 utf8mb4 / 前缀 lg_lin-trade_
-- 兼容 MySQL 5.5：DATETIME 不设 DEFAULT CURRENT_TIMESTAMP，时间由应用层(BaseModel)写入

-- ============ V_1.1 新增业务表（8 张） ============

-- 采购退货单（状态机 draft/submitted/done/voided）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_purchase_return` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no`         VARCHAR(32)  NOT NULL COMMENT '采购退货单号 PO-RT+YYMMDD+流水',
  `purchase_order_id` BIGINT UNSIGNED NULL COMMENT '来源采购订单 id',
  `supplier_id`       BIGINT UNSIGNED NULL COMMENT '供应商 id',
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/done/voided',
  `total_amount`      DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '退货总额（红字）',
  `checked_at`        DATETIME     NULL COMMENT '库存扣减完成时间',
  `remark`            VARCHAR(255) NULL COMMENT '备注',
  `void_reason`       VARCHAR(255) NULL COMMENT '作废原因',
  `created_by`        BIGINT UNSIGNED NULL COMMENT '创建人 user id',
  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`return_no`),
  KEY `idx_status` (`status`),
  KEY `idx_order` (`purchase_order_id`),
  KEY `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购退货单';

-- 采购退货明细
CREATE TABLE IF NOT EXISTS `lg_lin-trade_purchase_return_item` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id`         BIGINT UNSIGNED NOT NULL COMMENT '采购退货单 id',
  `purchase_order_item_id` BIGINT UNSIGNED NOT NULL COMMENT '来源采购订单明细 id',
  `product_id`        BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `quantity`          DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '退货数量',
  `price`             DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '单价（沿用原入库行单价）',
  `amount`            DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '行金额',
  `created_at`        DATETIME        NOT NULL,
  `updated_at`        DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`),
  KEY `idx_poitem` (`purchase_order_item_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购退货明细';

-- 销售退货单（状态机 draft/submitted/checked/done/voided；require_check=off 无 checked）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_sale_return` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no`     VARCHAR(32)  NOT NULL COMMENT '销售退货单号 SO-RT+YYMMDD+流水',
  `sale_order_id` BIGINT UNSIGNED NULL COMMENT '来源销售订单 id',
  `customer_id`   BIGINT UNSIGNED NULL COMMENT '客户 id',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/checked/done/voided',
  `require_check` TINYINT      NOT NULL DEFAULT 0 COMMENT '验收通过快照：0=否 1=是（取配置 trade.return.require_check）',
  `check_result`  VARCHAR(16)  NULL COMMENT '验退结果：passed/failed',
  `refund_flag`   TINYINT      NOT NULL DEFAULT 0 COMMENT '是否已退款/已冲应收',
  `total_amount`  DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '退货总额（红字）',
  `checked_at`    DATETIME     NULL COMMENT '验退通过时间',
  `refund_at`     DATETIME     NULL COMMENT '退款/冲应收标记时间',
  `remark`        VARCHAR(255) NULL COMMENT '备注',
  `void_reason`   VARCHAR(255) NULL COMMENT '作废原因',
  `created_by`    BIGINT UNSIGNED NULL COMMENT '创建人 user id',
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`return_no`),
  KEY `idx_status` (`status`),
  KEY `idx_order` (`sale_order_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售退货单';

-- 销售退货明细
CREATE TABLE IF NOT EXISTS `lg_lin-trade_sale_return_item` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id`      BIGINT UNSIGNED NOT NULL COMMENT '销售退货单 id',
  `sale_order_item_id` BIGINT UNSIGNED NOT NULL COMMENT '来源销售订单明细 id',
  `product_id`     BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `quantity`       DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '退货数量',
  `price`          DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '单价（沿用原出库行单价）',
  `amount`         DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '行金额',
  `created_at`     DATETIME        NOT NULL,
  `updated_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`),
  KEY `idx_soitem` (`sale_order_item_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售退货明细';

-- 客户信用
CREATE TABLE IF NOT EXISTS `lg_lin-trade_customer_credit` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`  BIGINT UNSIGNED NOT NULL COMMENT '客户 id',
  `credit_limit` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '信用额度（0=未设额度不可赊销）',
  `used_amount`  DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '已占用额度（已出库未结清销售金 - 已完成销售退货金）',
  `created_at`   DATETIME      NOT NULL,
  `updated_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户信用';

-- 价格规则（等级价/促销价/最低售价）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_price_rule` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`   BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `level_code`   VARCHAR(32)   NOT NULL COMMENT '客户等级：default/wholesale/vip',
  `level_price`  DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '等级价',
  `min_price`    DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '最低售价（缺省取参考售价×90%）',
  `promo_start`  DATE          NULL COMMENT '促销价开始日期',
  `promo_end`    DATE          NULL COMMENT '促销价结束日期',
  `created_at`   DATETIME      NOT NULL,
  `updated_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prod_level` (`product_id`,`level_code`),
  KEY `idx_level` (`level_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='价格规则';

-- 对账单（状态机 generated/confirmed/sent/voided）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_reconciliation` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rc_no`          VARCHAR(32)   NOT NULL COMMENT '对账单号 RC+YYMMDD+流水',
  `biz_type`       VARCHAR(16)   NOT NULL COMMENT 'customer/supplier',
  `target_id`      BIGINT UNSIGNED NOT NULL COMMENT '客户或供应商 id',
  `period_start`   DATE          NOT NULL COMMENT '期间开始',
  `period_end`     DATE          NOT NULL COMMENT '期间结束',
  `status`         VARCHAR(16)   NOT NULL DEFAULT 'generated' COMMENT 'generated/confirmed/sent/voided',
  `balance_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '期终余额（快照锁定）',
  `snapshot`       MEDIUMTEXT    NULL COMMENT '生成时点单据快照（JSON）',
  `remark`         VARCHAR(255)  NULL COMMENT '备注',
  `created_by`     BIGINT UNSIGNED NULL COMMENT '创建人 user id',
  `created_at`     DATETIME      NOT NULL,
  `updated_at`     DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`rc_no`),
  KEY `idx_type_target` (`biz_type`,`target_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对账单';

-- 导入日志（状态机 running/success/partial/failed）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_import_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_no`     VARCHAR(32)  NOT NULL COMMENT '导入批次号',
  `import_type`  VARCHAR(32)  NOT NULL COMMENT '导入类型：initial_stock/product/...',
  `file_name`    VARCHAR(255) NULL COMMENT '导入文件名',
  `total_rows`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总行数',
  `success_rows` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '成功行数',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'running' COMMENT 'running/success/partial/failed',
  `error_msg`    MEDIUMTEXT   NULL COMMENT '错误信息汇总',
  `operator`     VARCHAR(64)  NULL COMMENT '操作人',
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch` (`batch_no`),
  KEY `idx_type` (`import_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='导入日志';

-- ============ V_1.1 既有表字段增补 ============

-- 商品档案：参考进价/参考售价/ABC 分类
ALTER TABLE `lg_lin-trade_product`
  ADD COLUMN `reference_cost_price` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '参考进价' AFTER `safety_stock`,
  ADD COLUMN `reference_sale_price` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '参考售价' AFTER `reference_cost_price`,
  ADD COLUMN `abc_class` CHAR(1) NOT NULL DEFAULT '' COMMENT 'ABC 分类：A/B/C' AFTER `reference_sale_price`;

-- 库存：移动加权成本
ALTER TABLE `lg_lin-trade_inventory`
  ADD COLUMN `weighted_cost` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '移动加权成本' AFTER `quantity`;

-- 采购订单：预计到货日期
ALTER TABLE `lg_lin-trade_purchase_order`
  ADD COLUMN `expected_arrival_at` DATE NULL COMMENT '预计到货日期' AFTER `warehouse_id`;

-- 供应商：结算备注
ALTER TABLE `lg_lin-trade_supplier`
  ADD COLUMN `settle_note` VARCHAR(255) NULL COMMENT '结算备注' AFTER `remark`;

-- 客户：价格等级
ALTER TABLE `lg_lin-trade_customer`
  ADD COLUMN `price_level` VARCHAR(32) NOT NULL DEFAULT 'default' COMMENT '价格等级：default/wholesale/vip' AFTER `remark`;

-- 销售订单：整单折扣（允许行折扣与整单优惠时生效，配置 trade.sale.allow_discount）
ALTER TABLE `lg_lin-trade_sale_order`
  ADD COLUMN `discount_rate` DECIMAL(5,4) NOT NULL DEFAULT 0 COMMENT '整单折扣率' AFTER `total_amount`,
  ADD COLUMN `discount_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '整单优惠金额' AFTER `discount_rate`;

-- 销售订单明细：行折扣
ALTER TABLE `lg_lin-trade_sale_order_item`
  ADD COLUMN `discount_rate` DECIMAL(5,4) NOT NULL DEFAULT 0 COMMENT '行折扣率' AFTER `price`,
  ADD COLUMN `discount_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '行优惠金额' AFTER `discount_rate`;

-- 库存流水：扩充 change_type 枚举（initial/purchase_return/sale_return/reconcile_adjust），
-- 仅更新注释与说明，不改变既有类型取值（PRD 3.4.2）。
ALTER TABLE `lg_lin-trade_inventory_log`
  MODIFY COLUMN `change_type` VARCHAR(32) NOT NULL COMMENT '变动类型：purchase_in/sale_out/return_in/return_out/stocktake/transfer_in/transfer_out/initial/purchase_return/sale_return/reconcile_adjust' AFTER `warehouse_id`;