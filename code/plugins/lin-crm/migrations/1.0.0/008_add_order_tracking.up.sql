-- 林客功能包迁移 008 · 跟单（A+B）：follow_up.biz_type 枚举扩展 order
-- 跟单记录复用跟进表（biz_type='order'，biz_id=林销销售订单 lg_lin-trade_sale_order.id）。
-- 幂等：ENUM MODIFY 可重复执行；关联键为「客户名称弱匹配」（两包 id 空间不同，trade 侧无来源映射字段）。

ALTER TABLE `lg_lin-crm_follow_up`
  MODIFY COLUMN `biz_type` ENUM('customer','lead','opportunity','order') NOT NULL
  COMMENT '多态业务类型（客户/线索/商机/跟单订单复用）';
