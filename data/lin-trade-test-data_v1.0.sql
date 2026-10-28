-- =====================================================================
-- lin-trade 进销存 测试数据（覆盖 v1.1 新增功能页）
-- 说明：本脚本假设 lin_hub 库中 lin-trade 各表均已存在且为空。
--       覆盖：价格体系 / 采购跟踪 / 客户信用 / 呆滞清单 / 对账单 /
--             批量导入日志 / 草稿单保留期 等。
-- 基准日期：2026-10-22（与当前业务日期保持一致）
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- 一、基础主档
-- ---------------------------------------------------------------

-- 单位
INSERT INTO `lg_lin-trade_unit` (`id`,`name`,`status`,`created_at`,`updated_at`) VALUES
(1,'件',1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(2,'箱',1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(3,'吨',1,'2026-01-01 09:00:00','2026-01-01 09:00:00');

-- 分类
INSERT INTO `lg_lin-trade_category` (`id`,`name`,`parent_id`,`sort`,`status`,`created_at`,`updated_at`) VALUES
(1,'钢材',0,1,1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(2,'管材',0,2,1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(3,'板材',0,3,1,'2026-01-01 09:00:00','2026-01-01 09:00:00');

-- 仓库
INSERT INTO `lg_lin-trade_warehouse` (`id`,`name`,`is_default`,`status`,`created_at`,`updated_at`) VALUES
(1,'主仓库',1,1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(2,'广州分仓',0,1,'2026-01-01 09:00:00','2026-01-01 09:00:00'),
(3,'华东周转仓',0,1,'2026-01-05 09:00:00','2026-01-05 09:00:00');

-- 供应商
INSERT INTO `lg_lin-trade_supplier` (`id`,`supplier_no`,`name`,`contact`,`phone`,`address`,`remark`,`settle_note`,`status`,`created_at`,`updated_at`) VALUES
(1,'S001','唐山钢铁集团','刘强','13800000001','河北唐山','主营型材','月结','1','2026-01-01 09:00:00','2026-01-01 09:00:00'),
(2,'S002','山东钢管实业','王磊','13800000002','山东聊城','管材供应商','货到付款','1','2026-01-02 09:00:00','2026-01-02 09:00:00'),
(3,'S003','宝钢板材分销','赵敏','13800000003','上海宝山','板材华东分销','月结30天','1','2026-01-03 09:00:00','2026-01-03 09:00:00');

-- 客户
INSERT INTO `lg_lin-trade_customer` (`id`,`customer_no`,`name`,`contact`,`phone`,`address`,`remark`,`price_level`,`status`,`created_at`,`updated_at`) VALUES
(1,'C001','浙江鼎盛建设集团','陈工','13900000001','浙江杭州','大客户','vip','1','2026-01-05 09:00:00','2026-01-05 09:00:00'),
(2,'C002','上海启航装饰','李敏','13900000002','上海浦东','批发客户','wholesale','1','2026-01-06 09:00:00','2026-01-06 09:00:00'),
(3,'C003','广州天河五金城','周老板','13900000003','广东广州','散客','default','1','2026-01-08 09:00:00','2026-01-08 09:00:00');

-- 商品（product_no 唯一）
INSERT INTO `lg_lin-trade_product`
(`id`,`product_no`,`name`,`spec`,`unit_id`,`category_id`,`is_production`,`safety_stock`,`reference_cost_price`,`reference_sale_price`,`abc_class`,`status`,`created_at`,`updated_at`) VALUES
(1,'P001','热轧卷板','2.5mm×1250',3,3,0,60.0000,3650.0000,4050.0000,'A',1,'2026-01-05 09:00:00','2026-01-05 09:00:00'),
(2,'P002','冷轧卷板','0.8mm×1250',3,3,0,40.0000,4200.0000,4680.0000,'A',1,'2026-01-05 09:00:00','2026-01-05 09:00:00'),
(3,'P003','工字钢','14# 6米',3,1,0,25.0000,3900.0000,4350.0000,'B',1,'2026-01-06 09:00:00','2026-01-06 09:00:00'),
(4,'P004','无缝钢管','Φ89×6 12米',3,2,0,20.0000,4800.0000,5350.0000,'B',1,'2026-01-06 09:00:00','2026-01-06 09:00:00'),
(5,'P005','焊管','DN50 6米',3,2,0,30.0000,2950.0000,3280.0000,'C',1,'2026-01-07 09:00:00','2026-01-07 09:00:00'),
(6,'P006','镀锌板','1.2mm×1250',2,3,0,15.0000,4050.0000,4520.0000,'B',1,'2026-01-07 09:00:00','2026-01-07 09:00:00'),
(7,'P007','圆钢','Φ20 6米',3,1,0,35.0000,3300.0000,3680.0000,'A',1,'2026-01-08 09:00:00','2026-01-08 09:00:00'),
(8,'P008','角钢','50×50×5 6米',3,1,0,28.0000,3520.0000,3920.0000,'C',1,'2026-01-08 09:00:00','2026-01-08 09:00:00');

-- ---------------------------------------------------------------
-- 二、期初库存（含呆滞商品演示）
--     P005 焊管 主仓/华东仓：仅期初，近90天无流水 => 呆滞（占用金额大）
--     P008 角钢 广州分仓：仅期初，近90天无流水 => 呆滞
--     P001/P002 等参与采购入库，有近期流水 => 非呆滞
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_inventory` (`product_id`,`warehouse_id`,`quantity`,`weighted_cost`,`created_at`,`updated_at`) VALUES
(1,1,120.0000,3680.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(1,2,80.0000,3700.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(2,1,50.0000,4250.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(3,1,35.0000,3920.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(4,1,0.0000,0.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(5,1,200.0000,2980.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(5,3,60.0000,2990.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(6,1,25.0000,4080.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(7,1,40.0000,3320.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00'),
(8,2,120.0000,3550.0000,'2026-01-20 09:00:00','2026-01-20 09:00:00');

-- 库存流水（期初 initial 均在 90 天前，P001 补充近期采购入库流水）
INSERT INTO `lg_lin-trade_inventory_log`
(`product_id`,`warehouse_id`,`change_type`,`ref_id`,`before_qty`,`change_qty`,`after_qty`,`operator`,`remark`,`created_at`) VALUES
(1,1,'initial',NULL,0.0000,100.0000,100.0000,'system','期初建账','2026-01-20 09:00:00'),
(1,2,'initial',NULL,0.0000,80.0000,80.0000,'system','期初建账','2026-01-20 09:00:00'),
(2,1,'initial',NULL,0.0000,50.0000,50.0000,'system','期初建账','2026-01-20 09:00:00'),
(3,1,'initial',NULL,0.0000,35.0000,35.0000,'system','期初建账','2026-01-20 09:00:00'),
(5,1,'initial',NULL,0.0000,200.0000,200.0000,'system','期初建账','2026-01-20 09:00:00'),
(5,3,'initial',NULL,0.0000,60.0000,60.0000,'system','期初建账','2026-01-20 09:00:00'),
(6,1,'initial',NULL,0.0000,25.0000,25.0000,'system','期初建账','2026-01-20 09:00:00'),
(7,1,'initial',NULL,0.0000,40.0000,40.0000,'system','期初建账','2026-01-20 09:00:00'),
(8,2,'initial',NULL,0.0000,120.0000,120.0000,'system','期初建账','2026-01-20 09:00:00'),
-- 近期（<90天内）入库：采购单 PO-1002 主仓库 P001 到货 20 吨
(1,1,'purchase_in',1002,120.0000,20.0000,140.0000,'wordyhuang','采购入库 PO-1002','2026-10-05 10:00:00');

-- ---------------------------------------------------------------
-- 三、采购订单 + 明细（采购跟踪四色演示）
--     状态机：draft/submitted/partial/done/closed/voided
--     overdue: expected_arrival_at < 2026-10-22
--     arrival_alert: expected_arrival_at in [今天, 今天+3]
-- ---------------------------------------------------------------
-- (a) 超期 overdue：预计 10-15 到，仍 submitted
INSERT INTO `lg_lin-trade_purchase_order`
(`id`,`order_no`,`supplier_id`,`warehouse_id`,`expected_arrival_at`,`status`,`total_amount`,`inbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1001,'PO-1001',1,1,'2026-10-15','submitted',371200.0000,0,'唐钢热卷，暂无物流信息',1,'2026-10-01 09:00:00','2026-10-01 09:00:00');
INSERT INTO `lg_lin-trade_purchase_order_item`
(`order_id`,`product_id`,`quantity`,`received_qty`,`returned_qty`,`price`,`amount`,`created_at`,`updated_at`) VALUES
(1001,1,80.0000,0.0000,0.0000,3680.0000,294400.0000,'2026-10-01 09:00:00','2026-10-01 09:00:00'),
(1001,7,40.0000,0.0000,0.0000,3320.0000,132800.0000,'2026-10-01 09:00:00','2026-10-01 09:00:00');

-- (b) 到货提醒 arrival_alert：预计 10-24 到（3天内）
INSERT INTO `lg_lin-trade_purchase_order`
(`id`,`order_no`,`supplier_id`,`warehouse_id`,`expected_arrival_at`,`status`,`total_amount`,`inbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1002,'PO-1002',2,1,'2026-10-24','submitted',218850.0000,0,'冷卷即将到货',2,'2026-10-08 09:00:00','2026-10-08 09:00:00');
INSERT INTO `lg_lin-trade_purchase_order_item`
(`order_id`,`product_id`,`quantity`,`received_qty`,`returned_qty`,`price`,`amount`,`created_at`,`updated_at`) VALUES
(1002,2,42.5000,0.0000,0.0000,4250.0000,180625.0000,'2026-10-08 09:00:00','2026-10-08 09:00:00'),
(1002,6,10.0000,0.0000,0.0000,4090.0000,40900.0000,'2026-10-08 09:00:00','2026-10-08 09:00:00');

-- (c) 部分到货 partial：已收一部分，其余在途
INSERT INTO `lg_lin-trade_purchase_order`
(`id`,`order_no`,`supplier_id`,`warehouse_id`,`expected_arrival_at`,`status`,`total_amount`,`inbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1003,'PO-1003',3,2,'2026-11-05','partial',202500.0000,1,'部分到货',2,'2026-10-10 09:00:00','2026-10-10 09:00:00');
INSERT INTO `lg_lin-trade_purchase_order_item`
(`order_id`,`product_id`,`quantity`,`received_qty`,`returned_qty`,`price`,`amount`,`created_at`,`updated_at`) VALUES
(1003,3,30.0000,30.0000,0.0000,3900.0000,117000.0000,'2026-10-10 09:00:00','2026-10-10 09:00:00'),
(1003,8,25.0000,0.0000,0.0000,3420.0000,85500.0000,'2026-10-10 09:00:00','2026-10-10 09:00:00');

-- (d) 在途 transit：预计 11-15 / 11-20，submitted，未到货
INSERT INTO `lg_lin-trade_purchase_order`
(`id`,`order_no`,`supplier_id`,`warehouse_id`,`expected_arrival_at`,`status`,`total_amount`,`inbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1004,'PO-1004',1,3,'2026-11-15','submitted',78400.0000,0,'华东仓补货',2,'2026-10-12 09:00:00','2026-10-12 09:00:00');
INSERT INTO `lg_lin-trade_purchase_order_item`
(`order_id`,`product_id`,`quantity`,`received_qty`,`returned_qty`,`price`,`amount`,`created_at`,`updated_at`) VALUES
(1004,5,20.0000,0.0000,0.0000,2950.0000,59000.0000,'2026-10-12 09:00:00','2026-10-12 09:00:00'),
(1004,4,4.0000,0.0000,0.0000,4850.0000,19400.0000,'2026-10-12 09:00:00','2026-10-12 09:00:00');

-- (e) 已完成 done（供对账单 / 库存），以及已关闭/作废样例
INSERT INTO `lg_lin-trade_purchase_order`
(`id`,`order_no`,`supplier_id`,`warehouse_id`,`expected_arrival_at`,`status`,`total_amount`,`inbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1005,'PO-1005',2,1,'2026-09-28','done',426000.0000,2,'已完成入库',1,'2026-09-20 09:00:00','2026-09-25 09:00:00'),
(1006,'PO-1006',3,2,'2026-08-10','closed',198000.0000,1,'已关闭',2,'2026-08-05 09:00:00','2026-08-20 09:00:00'),
(1007,'PO-1007',1,1,'2026-07-01','voided',0.0000,0,'作废样本',2,'2026-06-25 09:00:00','2026-06-26 09:00:00');
INSERT INTO `lg_lin-trade_purchase_order_item`
(`order_id`,`product_id`,`quantity`,`received_qty`,`returned_qty`,`price`,`amount`,`created_at`,`updated_at`) VALUES
(1005,1,60.0000,60.0000,0.0000,3660.0000,219600.0000,'2026-09-20 09:00:00','2026-09-25 09:00:00'),
(1005,2,50.0000,50.0000,0.0000,4250.0000,212500.0000,'2026-09-20 09:00:00','2026-09-25 09:00:00'),
(1006,6,20.0000,20.0000,0.0000,4050.0000,81000.0000,'2026-08-05 09:00:00','2026-08-20 09:00:00'),
(1006,8,30.0000,30.0000,0.0000,3520.0000,105600.0000,'2026-08-05 09:00:00','2026-08-20 09:00:00');

-- 补充 PO-1005 已经入库的库存流水（<90天，体现近期流水）
INSERT INTO `lg_lin-trade_inventory_log`
(`product_id`,`warehouse_id`,`change_type`,`ref_id`,`before_qty`,`change_qty`,`after_qty`,`operator`,`remark`,`created_at`) VALUES
(1,1,'purchase_in',1005,0.0000,60.0000,60.0000,'wordyhuang','采购入库 PO-1005','2026-09-25 09:00:00'),
(2,1,'purchase_in',1005,0.0000,50.0000,50.0000,'wordyhuang','采购入库 PO-1005','2026-09-25 09:00:00');

-- ---------------------------------------------------------------
-- 四、采购退货（供对账单 / 退货单列表）
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_purchase_return`
(`id`,`return_no`,`purchase_order_id`,`supplier_id`,`status`,`total_amount`,`checked_at`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(2001,'PO-RT2001',1005,2,'done',21250.0000,'2026-09-27 10:00:00','冷卷有轻微划伤退回',1,'2026-09-26 09:00:00','2026-09-27 09:00:00'),
(2002,'PO-RT2002',1006,3,'draft',44200.0000,NULL,'角钢待定退回（草稿样本）',2,'2026-09-24 09:00:00','2026-09-24 09:00:00');

-- ---------------------------------------------------------------
-- 五、价格体系：价格规则 price_rule + 最低售价审批 price_approval
-- ---------------------------------------------------------------
-- 商品1 热轧卷板：给 vip 客户等级价 + default 促销价（在促期内）
INSERT INTO `lg_lin-trade_price_rule`
(`id`,`product_id`,`level_code`,`level_price`,`min_price`,`promo_price`,`promo_start`,`promo_end`,`created_at`,`updated_at`) VALUES
(1,1,'vip',3980.0000,3645.0000,3880.0000,'2026-10-01','2026-10-31','2026-10-01 09:00:00','2026-10-01 09:00:00'),
(2,1,'wholesale',4050.0000,3645.0000,0.0000,NULL,NULL,'2026-10-01 09:00:00','2026-10-01 09:00:00'),
(3,1,'default',4050.0000,3645.0000,3880.0000,'2026-10-01','2026-10-31','2026-10-01 09:00:00','2026-10-01 09:00:00');
-- 商品2 冷轧卷板：给 default 档（无促销），vip 等级价，min_price 为参考售价的90%
INSERT INTO `lg_lin-trade_price_rule`
(`id`,`product_id`,`level_code`,`level_price`,`min_price`,`promo_price`,`promo_start`,`promo_end`,`created_at`,`updated_at`) VALUES
(4,2,'default',4680.0000,4212.0000,0.0000,NULL,NULL,'2026-10-05 09:00:00','2026-10-05 09:00:00'),
(5,2,'vip',4550.0000,4212.0000,0.0000,NULL,NULL,'2026-10-05 09:00:00','2026-10-05 09:00:00');

-- 最低售价审批：一条 pending（待审批）、一条 approved（已通过）
INSERT INTO `lg_lin-trade_price_approval`
(`id`,`sale_order_id`,`sale_order_item_id`,`product_id`,`applied_price`,`min_price`,`status`,`approver_id`,`reason`,`created_by`,`created_at`,`updated_at`) VALUES
(1,3002,3002,2,4150.0000,4212.0000,'pending',NULL,NULL,2,'2026-10-20 09:00:00','2026-10-20 09:00:00'),
(2,3003,3003,4,4950.0000,5000.0000,'approved',1,'批量让利',2,'2026-10-18 09:00:00','2026-10-18 09:00:00');

-- ---------------------------------------------------------------
-- 六、销售订单 + 明细（含 赊销占用 / 已结清 演示）
-- ---------------------------------------------------------------
-- (a) 已结清 done：C001 已出库已收款（供 信用占用已清零 & 对账收款）
INSERT INTO `lg_lin-trade_sale_order`
(`id`,`order_no`,`customer_id`,`warehouse_id`,`status`,`total_amount`,`discount_rate`,`discount_amount`,`credit_settled`,`settled_at`,`outbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(3001,'SO-3001',1,1,'done',318400.0000,1.0000,0.0000,1,'2026-09-29 17:00:00',1,'已完成并结清',1,'2026-09-15 09:00:00','2026-09-29 09:00:00');
INSERT INTO `lg_lin-trade_sale_order_item`
(`order_id`,`product_id`,`quantity`,`outbound_qty`,`returned_qty`,`price`,`discount_rate`,`discount_amount`,`amount`,`created_at`,`updated_at`) VALUES
(3001,1,80.0000,80.0000,0.0000,3980.0000,1.0000,0.0000,318400.0000,'2026-09-15 09:00:00','2026-09-29 09:00:00');

-- (b) 未结清收款 done：C001 已出库但未结清 => 产生信用占用
INSERT INTO `lg_lin-trade_sale_order`
(`id`,`order_no`,`customer_id`,`warehouse_id`,`status`,`total_amount`,`discount_rate`,`discount_amount`,`credit_settled`,`settled_at`,`outbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(3002,'SO-3002',1,1,'done',86800.0000,1.0000,0.0000,0,NULL,1,'笔单价4150低于最低价需求审批',1,'2026-10-18 09:00:00','2026-10-18 09:00:00');
INSERT INTO `lg_lin-trade_sale_order_item`
(`order_id`,`product_id`,`quantity`,`outbound_qty`,`returned_qty`,`price`,`discount_rate`,`discount_amount`,`amount`,`created_at`,`updated_at`) VALUES
(3002,2,20.0000,20.0000,0.0000,4150.0000,1.0000,0.0000,83000.0000,'2026-10-18 09:00:00','2026-10-18 09:00:00'),
(3002,8,1.0000,1.0000,0.0000,3800.0000,1.0000,0.0000,3800.0000,'2026-10-18 09:00:00','2026-10-18 09:00:00');

-- (c) 部分出库 partial：C002
INSERT INTO `lg_lin-trade_sale_order`
(`id`,`order_no`,`customer_id`,`warehouse_id`,`status`,`total_amount`,`discount_rate`,`discount_amount`,`credit_settled`,`settled_at`,`outbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(3003,'SO-3003',2,2,'partial',50150.0000,0.9800,1020.0000,0,NULL,1,'大头角钢部分交付',2,'2026-10-19 09:00:00','2026-10-19 09:00:00');
INSERT INTO `lg_lin-trade_sale_order_item`
(`order_id`,`product_id`,`quantity`,`outbound_qty`,`returned_qty`,`price`,`discount_rate`,`discount_amount`,`amount`,`created_at`,`updated_at`) VALUES
(3003,4,10.0000,5.0000,0.0000,5350.0000,0.9800,535.0000,52430.0000,'2026-10-19 09:00:00','2026-10-19 09:00:00'),
(3003,6,1.0000,0.0000,0.0000,4520.0000,0.9800,90.4000,4430.0000,'2026-10-19 09:00:00','2026-10-19 09:00:00');

-- (d) 新增草稿/已提交/作废样例
INSERT INTO `lg_lin-trade_sale_order`
(`id`,`order_no`,`customer_id`,`warehouse_id`,`status`,`total_amount`,`discount_rate`,`discount_amount`,`credit_settled`,`settled_at`,`outbound_no`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(3004,'SO-3004',3,1,'submitted',90000.0000,1.0000,0.0000,0,NULL,0,'在途销售样本',2,'2026-10-21 09:00:00','2026-10-21 09:00:00'),
(3005,'SO-3005',1,1,'voided',0.0000,1.0000,0.0000,0,NULL,0,'作废样本',2,'2026-10-01 09:00:00','2026-10-02 09:00:00');

-- ---------------------------------------------------------------
-- 七、销售退货（已完成冲减信用占用，供对账）
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_sale_return`
(`id`,`return_no`,`sale_order_id`,`customer_id`,`status`,`require_check`,`check_result`,`refund_flag`,`total_amount`,`checked_at`,`refund_at`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(4001,'SO-RT4001',3001,1,'done',1,'passed',1,15920.0000,'2026-09-30 10:00:00','2026-09-30 11:00:00','卷板售后退货',1,'2026-09-30 09:00:00','2026-09-30 09:00:00'),
(4002,'SO-RT4002',3003,2,'draft',0,NULL,0,0.0000,NULL,NULL,'退货草稿样本',2,'2026-09-20 09:00:00','2026-09-20 09:00:00');

-- ---------------------------------------------------------------
-- 八、客户信用：额度 + 留痕日志（供 客户信用页 / 预检 / 结清）
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_customer_credit`
(`id`,`customer_id`,`credit_limit`,`used_amount`,`created_at`,`updated_at`) VALUES
(1,1,200000.0000,0.0000,'2026-01-05 09:00:00','2026-10-18 09:00:00'),
(2,2,50000.0000,0.0000,'2026-01-06 09:00:00','2026-10-19 09:00:00'),
(3,3,0.0000,0.0000,'2026-01-08 09:00:00','2026-01-08 09:00:00');

INSERT INTO `lg_lin-trade_credit_log`
(`customer_id`,`action`,`before_amount`,`after_amount`,`operator`,`ref_type`,`ref_id`,`remark`,`created_at`) VALUES
(1,'set_limit',0.0000,200000.0000,1,'credit',NULL,'初始授信','2026-01-05 09:00:00','2026-01-05 09:00:00'),
(1,'manual_settle',86800.0000,0.0000,1,'sale_order',3001,'结清 SO-3001','2026-09-29 17:00:00','2026-09-29 17:00:00'),
(2,'set_limit',0.0000,50000.0000,1,'credit',NULL,'初始授信','2026-01-06 09:00:00','2026-01-06 09:00:00');

-- ---------------------------------------------------------------
-- 九、对账单 reconciliation（客户 / 供应商各一张，含快照）
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_reconciliation`
(`id`,`rc_no`,`biz_type`,`target_id`,`period_start`,`period_end`,`status`,`balance_amount`,`snapshot`,`remark`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'RC-2026-09-001','customer',1,'2026-09-01','2026-09-30','sent',302480.0000,
'{"biz_type":"customer","target_id":1,"period_start":"2026-09-01","period_end":"2026-09-30","opening":0,"in_amount":318400,"return_amount":15920,"pay_amount":0,"closing":302480}',
'浙江鼎盛 9月对账','1','2026-10-01 09:00:00','2026-10-01 09:00:00'),
(2,'RC-2026-09-002','supplier',2,'2026-09-01','2026-09-30','confirmed',426000.0000,
'{"biz_type":"supplier","target_id":2,"period_start":"2026-09-01","period_end":"2026-09-30","opening":0,"in_amount":426000,"return_amount":21250,"pay_amount":0,"closing":404750}',
'山东钢管 9月对账','1','2026-10-02 09:00:00','2026-10-02 09:00:00'),
(3,'RC-2026-10-001','customer',2,'2026-10-01','2026-10-31','generated',50150.0000,
'{"biz_type":"customer","target_id":2,"period_start":"2026-10-01","period_end":"2026-10-31","opening":0,"in_amount":50150,"return_amount":0,"pay_amount":0,"closing":50150}',
'上海启航 10月对账待确认','1','2026-10-21 09:00:00','2026-10-21 09:00:00');

-- ---------------------------------------------------------------
-- 十、导入日志（供 工具中心-批量导入 历史日志）
-- ---------------------------------------------------------------
INSERT INTO `lg_lin-trade_import_log`
(`id`,`batch_no`,`import_type`,`file_name`,`total_rows`,`success_rows`,`status`,`error_msg`,`operator`,`created_at`,`updated_at`) VALUES
(1,'IMP20260915001','initial_stock','期初库存-202609.csv',18,18,'success',NULL,'wordyhuang','2026-09-15 10:00:00','2026-09-15 10:00:30'),
(2,'IMP20261011002','initial_stock','期初库存-补充.csv',6,4,'partial','第3行[商品编码]P999不存在；第5行[数量]非法','wordyhuang','2026-10-11 14:00:00','2026-10-11 14:00:20');