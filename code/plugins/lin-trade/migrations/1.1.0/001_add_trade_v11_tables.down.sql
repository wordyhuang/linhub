-- 林销（进销存）功能包 回滚迁移 v1.1.0
-- 与 001_add_trade_v11_tables.up.sql 对称
-- 兼容 MySQL 5.5：ALTER 回滚在 MySQL 5.5 无 DROP COLUMN IF EXISTS 语法，此处按增补反向删除

-- 库存流水枚举注释还原
ALTER TABLE `lg_lin-trade_inventory_log`
  MODIFY COLUMN `change_type` VARCHAR(32) NOT NULL COMMENT '变动类型：purchase_in/sale_out/return_in/return_out/stocktake/transfer_in/transfer_out' AFTER `warehouse_id`;

-- 明细字段回滚先于主表（无外键，顺序无强约束，仅为可读性）
ALTER TABLE `lg_lin-trade_sale_order_item`
  DROP COLUMN `discount_rate`,
  DROP COLUMN `discount_amount`;

ALTER TABLE `lg_lin-trade_sale_order`
  DROP COLUMN `discount_rate`,
  DROP COLUMN `discount_amount`;

ALTER TABLE `lg_lin-trade_customer`
  DROP COLUMN `price_level`;

ALTER TABLE `lg_lin-trade_supplier`
  DROP COLUMN `settle_note`;

ALTER TABLE `lg_lin-trade_purchase_order`
  DROP COLUMN `expected_arrival_at`;

ALTER TABLE `lg_lin-trade_inventory`
  DROP COLUMN `weighted_cost`;

ALTER TABLE `lg_lin-trade_product`
  DROP COLUMN `abc_class`,
  DROP COLUMN `reference_sale_price`,
  DROP COLUMN `reference_cost_price`;

-- 新增表删除（倒序）
DROP TABLE IF EXISTS `lg_lin-trade_import_log`;
DROP TABLE IF EXISTS `lg_lin-trade_reconciliation`;
DROP TABLE IF EXISTS `lg_lin-trade_price_rule`;
DROP TABLE IF EXISTS `lg_lin-trade_customer_credit`;
DROP TABLE IF EXISTS `lg_lin-trade_sale_return_item`;
DROP TABLE IF EXISTS `lg_lin-trade_sale_return`;
DROP TABLE IF EXISTS `lg_lin-trade_purchase_return_item`;
DROP TABLE IF EXISTS `lg_lin-trade_purchase_return`;