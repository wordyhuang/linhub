-- 林销（进销存）功能包 价格体系/客户信用 回滚迁移 v1.1.0
-- 与 002_add_price_credit.up.sql 对称
-- 兼容 MySQL 5.5：DROP COLUMN 直接写列名（无 IF EXISTS 语法）

DROP TABLE IF EXISTS `lg_lin-trade_credit_log`;
DROP TABLE IF EXISTS `lg_lin-trade_price_approval`;

ALTER TABLE `lg_lin-trade_sale_order`
  DROP COLUMN `settled_at`,
  DROP COLUMN `credit_settled`;

ALTER TABLE `lg_lin-trade_price_rule`
  DROP COLUMN `promo_price`;