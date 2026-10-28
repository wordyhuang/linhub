-- 008_add_order_tracking.down.sql · 回滚跟单枚举扩展（先清理 order 记录避免 MODIFY 截断失败）
DELETE FROM `lg_lin-crm_follow_up` WHERE `biz_type` = 'order';

ALTER TABLE `lg_lin-crm_follow_up`
  MODIFY COLUMN `biz_type` ENUM('customer','lead','opportunity') NOT NULL
  COMMENT '多态业务类型（客户/线索/商机复用）';
