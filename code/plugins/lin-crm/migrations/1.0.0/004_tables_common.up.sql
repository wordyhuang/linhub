-- 林客功能包迁移 004 · 通用表：follow_up / notify_log / audit_log
-- 幂等：可重复执行

CREATE TABLE IF NOT EXISTS `lg_lin-crm_follow_up` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `biz_type` ENUM('customer','lead','opportunity') NOT NULL COMMENT '多态业务类型（客户/线索/商机复用）',
  `biz_id` BIGINT UNSIGNED NOT NULL COMMENT '业务对象 id',
  `method` ENUM('phone','visit','wechat','other') NOT NULL DEFAULT 'phone' COMMENT '跟进方式',
  `content` VARCHAR(1000) NOT NULL COMMENT '跟进内容',
  `next_at` DATETIME DEFAULT NULL COMMENT '下次跟进时间（到期生成待办提醒）',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '跟进人',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_biz` (`biz_type`,`biz_id`),
  KEY `idx_next_at` (`next_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跟进记录（RL-2：内容可补充、方式/日期禁改）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_notify_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收人',
  `title` VARCHAR(128) NOT NULL COMMENT '通知标题',
  `content` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '通知内容',
  `biz_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联业务类型',
  `biz_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联业务 id',
  `is_read` TINYINT NOT NULL DEFAULT 0 COMMENT '已读 1/未读 0',
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知记录（站内信中心）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` BIGINT UNSIGNED NOT NULL COMMENT '操作人',
  `action` VARCHAR(64) NOT NULL COMMENT '行为（login/export/delete/transfer/phone_view/merge/release）',
  `biz_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '业务类型',
  `biz_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '业务 id',
  `detail` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '详情（导出范围/合并主从档等）',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '操作 IP',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action_created` (`action`,`created_at`),
  KEY `idx_actor` (`actor_id`),
  KEY `idx_biz` (`biz_type`,`biz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审计日志（保留 ≥1 年，NFR-BAK 侧按月归档）';
