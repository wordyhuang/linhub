-- 林销（进销存）功能包 回滚迁移 v1.0.0

DROP TABLE IF EXISTS `lg_lin-trade_transfer_item`;
DROP TABLE IF EXISTS `lg_lin-trade_transfer`;
DROP TABLE IF EXISTS `lg_lin-trade_stocktake_item`;
DROP TABLE IF EXISTS `lg_lin-trade_stocktake`;
DROP TABLE IF EXISTS `lg_lin-trade_sale_order_item`;
DROP TABLE IF EXISTS `lg_lin-trade_sale_order`;
DROP TABLE IF EXISTS `lg_lin-trade_purchase_order_item`;
DROP TABLE IF EXISTS `lg_lin-trade_purchase_order`;
DROP TABLE IF EXISTS `lg_lin-trade_inventory_log`;
DROP TABLE IF EXISTS `lg_lin-trade_inventory`;
DROP TABLE IF EXISTS `lg_lin-trade_warehouse`;
DROP TABLE IF EXISTS `lg_lin-trade_product`;
DROP TABLE IF EXISTS `lg_lin-trade_customer`;
DROP TABLE IF EXISTS `lg_lin-trade_supplier`;
DROP TABLE IF EXISTS `lg_lin-trade_unit`;
DROP TABLE IF EXISTS `lg_lin-trade_category`;