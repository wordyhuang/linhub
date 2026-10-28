-- 林销（进销存）功能包 建表迁移 v1.0.0
-- 引擎 InnoDB / 字符集 utf8mb4 / 前缀 lg_lin-trade_

CREATE TABLE IF NOT EXISTS `lg_lin-trade_category` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(64)   NOT NULL COMMENT '分类名称',
  `parent_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类 id，0=顶级',
  `sort`        INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '排序',
  `status`      TINYINT       NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME      NOT NULL ,
  `updated_at`  DATETIME      NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_unit` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(32) NOT NULL COMMENT '单位名称，如 件/箱/千克',
  `status`      TINYINT     NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME    NOT NULL ,
  `updated_at`  DATETIME    NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计量单位';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_supplier` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_no` VARCHAR(32)  NOT NULL COMMENT '供应商编码',
  `name`        VARCHAR(128) NOT NULL COMMENT '供应商名称',
  `contact`     VARCHAR(64)  NULL COMMENT '联系人',
  `phone`       VARCHAR(32)  NULL COMMENT '联系电话',
  `address`     VARCHAR(255) NULL COMMENT '地址',
  `remark`      VARCHAR(255) NULL COMMENT '备注',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME     NOT NULL ,
  `updated_at`  DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`supplier_no`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_customer` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_no` VARCHAR(32)  NOT NULL COMMENT '客户编码',
  `name`        VARCHAR(128) NOT NULL COMMENT '客户名称',
  `contact`     VARCHAR(64)  NULL COMMENT '联系人',
  `phone`       VARCHAR(32)  NULL COMMENT '联系电话',
  `address`     VARCHAR(255) NULL COMMENT '地址',
  `remark`      VARCHAR(255) NULL COMMENT '备注',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME     NOT NULL ,
  `updated_at`  DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`customer_no`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_product` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_no`    VARCHAR(32)  NOT NULL COMMENT '商品编码',
  `name`          VARCHAR(128) NOT NULL COMMENT '商品名称',
  `spec`          VARCHAR(128) NULL COMMENT '规格',
  `unit_id`       BIGINT UNSIGNED NULL COMMENT '计量单位 id',
  `category_id`   BIGINT UNSIGNED NULL COMMENT '分类 id',
  `is_production` TINYINT      NOT NULL DEFAULT 0 COMMENT '是否可生产（1=是，关联林造）',
  `safety_stock`  DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '安全库存',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1生效 0停用',
  `created_at`    DATETIME     NOT NULL ,
  `updated_at`    DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`product_no`),
  KEY `idx_name` (`name`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品档案';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_warehouse` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64) NOT NULL COMMENT '仓库名称',
  `is_default` TINYINT     NOT NULL DEFAULT 0 COMMENT '是否默认仓库',
  `status`     TINYINT     NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME    NOT NULL ,
  `updated_at` DATETIME    NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_inventory` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`  BIGINT UNSIGNED  NOT NULL COMMENT '商品 id',
  `warehouse_id` BIGINT UNSIGNED NOT NULL COMMENT '仓库 id',
  `quantity`    DECIMAL(18,4)    NOT NULL DEFAULT 0 COMMENT '当前库存数量',
  `created_at`  DATETIME     NOT NULL ,
  `updated_at`  DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prod_wh` (`product_id`,`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实时库存';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_inventory_log` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`  BIGINT UNSIGNED  NOT NULL COMMENT '商品 id',
  `warehouse_id` BIGINT UNSIGNED NOT NULL COMMENT '仓库 id',
  `change_type` VARCHAR(32)      NOT NULL COMMENT '变动类型：purchase_in/sale_out/return_in/return_out/stocktake/transfer_in/transfer_out',
  `ref_id`      BIGINT UNSIGNED  NULL COMMENT '来源单据 id（采购/销售/盘点/调拨）',
  `before_qty`  DECIMAL(18,4)    NOT NULL DEFAULT 0 COMMENT '变动前数量',
  `change_qty`  DECIMAL(18,4)    NOT NULL DEFAULT 0 COMMENT '变动数量（入正出负）',
  `after_qty`   DECIMAL(18,4)    NOT NULL DEFAULT 0 COMMENT '变动后数量',
  `operator`    VARCHAR(64)      NULL COMMENT '操作人',
  `remark`      VARCHAR(255)     NULL COMMENT '备注',
  `created_at`  DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_ref` (`ref_id`),
  KEY `idx_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存流水';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_purchase_order` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`     VARCHAR(32)  NOT NULL COMMENT '采购订单号',
  `supplier_id`  BIGINT UNSIGNED NULL COMMENT '供应商 id',
  `warehouse_id` BIGINT UNSIGNED NULL COMMENT '入库仓库',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/partial/done/closed/voided',
  `total_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '总额',
  `inbound_no`   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '已入库明细行数',
  `remark`       VARCHAR(255) NULL COMMENT '备注',
  `void_reason`  VARCHAR(255) NULL COMMENT '作废原因',
  `created_by`   BIGINT UNSIGNED NULL COMMENT '创建人 user id',
  `created_at`   DATETIME     NOT NULL ,
  `updated_at`   DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购订单';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_purchase_order_item` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     BIGINT UNSIGNED NOT NULL COMMENT '采购订单 id',
  `product_id`   BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `quantity`     DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '计划数量',
  `received_qty` DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '已入库数量',
  `returned_qty` DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '已退货数量',
  `price`        DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '单价',
  `amount`       DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '行金额',
  `created_at`   DATETIME       NOT NULL ,
  `updated_at`   DATETIME       NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采购订单明细';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_sale_order` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`     VARCHAR(32)  NOT NULL COMMENT '销售订单号',
  `customer_id`  BIGINT UNSIGNED NULL COMMENT '客户 id',
  `warehouse_id` BIGINT UNSIGNED NULL COMMENT '出库仓库',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/partial/done/closed/voided',
  `total_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '总额',
  `outbound_no`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '已出库明细行数',
  `remark`       VARCHAR(255) NULL COMMENT '备注',
  `void_reason`  VARCHAR(255) NULL COMMENT '作废原因',
  `created_by`   BIGINT UNSIGNED NULL COMMENT '创建人 user id',
  `source_topic` VARCHAR(64)   NULL COMMENT '来源（如 lg.crm.deal.closed 转单）',
  `created_at`   DATETIME     NOT NULL ,
  `updated_at`   DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售订单';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_sale_order_item` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     BIGINT UNSIGNED NOT NULL COMMENT '销售订单 id',
  `product_id`   BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `quantity`     DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '计划数量',
  `outbound_qty` DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '已出库数量',
  `returned_qty` DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '已退货数量',
  `price`        DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '单价',
  `amount`       DECIMAL(18,4)  NOT NULL DEFAULT 0 COMMENT '行金额',
  `created_at`   DATETIME       NOT NULL ,
  `updated_at`   DATETIME       NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售订单明细';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_stocktake` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stocktake_no` VARCHAR(32)  NOT NULL COMMENT '盘点单号',
  `warehouse_id` BIGINT UNSIGNED NOT NULL COMMENT '仓库 id',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'taking' COMMENT 'taking(盘点中)/confirmed(已确认)/adjusted(已调整)',
  `operator`     VARCHAR(64)  NULL COMMENT '操作人',
  `remark`       VARCHAR(255) NULL COMMENT '备注',
  `created_at`   DATETIME     NOT NULL ,
  `updated_at`   DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`stocktake_no`),
  KEY `idx_status` (`status`),
  KEY `idx_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存盘点单';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_stocktake_item` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stocktake_id` BIGINT UNSIGNED NOT NULL COMMENT '盘点单 id',
  `product_id`   BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `book_qty`     DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '账面数量',
  `actual_qty`   DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '实盘数量',
  `diff_qty`     DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '盘盈/盘亏（actual - book）',
  `created_at`   DATETIME      NOT NULL ,
  `updated_at`   DATETIME      NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_stocktake` (`stocktake_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='盘点明细';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_transfer` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_no`      VARCHAR(32)  NOT NULL COMMENT '调拨单号',
  `from_warehouse_id` BIGINT UNSIGNED NOT NULL COMMENT '调出仓库',
  `to_warehouse_id`  BIGINT UNSIGNED NOT NULL COMMENT '调入仓库',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'submitted' COMMENT 'submitted/done',
  `operator`         VARCHAR(64)  NULL COMMENT '操作人',
  `remark`           VARCHAR(255) NULL COMMENT '备注',
  `created_at`       DATETIME     NOT NULL ,
  `updated_at`       DATETIME     NOT NULL ,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no` (`transfer_no`),
  KEY `idx_from` (`from_warehouse_id`),
  KEY `idx_to` (`to_warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存调拨单';

CREATE TABLE IF NOT EXISTS `lg_lin-trade_transfer_item` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_id`  BIGINT UNSIGNED NOT NULL COMMENT '调拨单 id',
  `product_id`   BIGINT UNSIGNED NOT NULL COMMENT '商品 id',
  `quantity`     DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT '调拨数量',
  `created_at`   DATETIME        NOT NULL ,
  `updated_at`   DATETIME        NOT NULL ,
  PRIMARY KEY (`id`),
  KEY `idx_transfer` (`transfer_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='调拨明细';