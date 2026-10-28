-- 林销（进销存）功能包 价格体系/客户信用 迁移增量 v1.1.0
-- PRD《林工_林销_进销存产品需求_v1.1》 3.8 价格体系 / 3.9 客户信用
-- 引擎 InnoDB / 字符集 utf8mb4 / 前缀 lg_lin-trade_
-- 兼容 MySQL 5.5：DATETIME 不设 DEFAULT CURRENT_TIMESTAMP；无 DROP COLUMN IF EXISTS（见 .down.sql）

-- ============ 表结构增量 ============

-- 价格规则：增加促销价字段（PRD 3.8.4 促销价 = 新价 + 起止日期，促销期内带价优先级最高）
ALTER TABLE `lg_lin-trade_price_rule`
  ADD COLUMN `promo_price` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '促销价（促销期内带价优先级最高）' AFTER `min_price`;

-- 销售订单：结清标记（客户信用占用以已出库为准，结清来源 lg.finance.receivable.status 或手工标记）
ALTER TABLE `lg_lin-trade_sale_order`
  ADD COLUMN `credit_settled` TINYINT NOT NULL DEFAULT 0 COMMENT '信用占用是否已结清：0=未结清 1=已结清' AFTER `discount_amount`,
  ADD COLUMN `settled_at` DATETIME NULL COMMENT '结清时间' AFTER `credit_settled`;

-- 价格审批留痕（PRD 3.8.1：销售提交单价低于最低售价时走审批 trade:price:approve，记录单据、行、申请价、审批人、时间、理由）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_price_approval` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_order_id`     BIGINT UNSIGNED NOT NULL COMMENT '销售订单 id',
  `sale_order_item_id` BIGINT UNSIGNED NULL COMMENT '销售订单明细 id（行审批）',
  `product_id`        BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `applied_price`     DECIMAL(18,4) NOT NULL COMMENT '申请价（低于最低售价）',
  `min_price`         DECIMAL(18,4) NOT NULL COMMENT '最低售价',
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected',
  `approver_id`       BIGINT UNSIGNED NULL COMMENT '审批人 user id',
  `reason`            VARCHAR(255) NULL COMMENT '申请/审批理由',
  `created_by`        BIGINT UNSIGNED NULL COMMENT '申请人 user id',
  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`sale_order_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='价格审批留痕';

-- 信用操作留痕（PRD 3.9.1 额度调整预留改前/改后；3.9.2 手工收款释放占用留痕；3.9.3 支撑结清来源留痕）
CREATE TABLE IF NOT EXISTS `lg_lin-trade_credit_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`   BIGINT UNSIGNED NOT NULL COMMENT '客户 id',
  `action`        VARCHAR(32)  NOT NULL COMMENT '动作：set_limit/manual_settle/settle',
  `before_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '改前额度或占用',
  `after_amount`  DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '改后额度或占用',
  `operator`      BIGINT UNSIGNED NULL COMMENT '操作人 user id',
  `ref_type`      VARCHAR(32)  NULL COMMENT '关联单据类型：sale_order/sale_return',
  `ref_id`        BIGINT UNSIGNED NULL COMMENT '关联单据 id',
  `remark`        VARCHAR(255) NULL COMMENT '备注',
  `created_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户信用操作留痕';