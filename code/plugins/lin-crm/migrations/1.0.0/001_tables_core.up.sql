-- 林客功能包迁移 001 · 核心表：客户 / 联系人 / 线索
-- MySQL 5.5 约束：InnoDB + utf8mb4；单索引 ≤767 字节；created_at 为 TIMESTAMP（每表仅一列 CURRENT_TIMESTAMP 默认值）
-- 幂等：可重复执行

CREATE TABLE IF NOT EXISTS `lg_lin-crm_customer` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL COMMENT '客户编码 KH+yyyyMMdd+4位流水',
  `name` VARCHAR(191) NOT NULL COMMENT '客户名称（去空格后唯一，RL-1 兜底）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号（展示脱敏，crm:phone:view_full 解权）',
  `industry` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '行业（字典 lg_lin-crm_dict:industry）',
  `source` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '来源（字典 lg_lin-crm_dict:source）',
  `level` VARCHAR(16) NOT NULL DEFAULT 'normal' COMMENT '等级（字典 lg_lin-crm_dict:level）',
  `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '负责人用户 id（0=公海无归属）',
  `pool_status` ENUM('public_pool','private_pool') NOT NULL DEFAULT 'public_pool' COMMENT '池归属 STM-CUS',
  `protect_until` DATETIME DEFAULT NULL COMMENT '保护期截止（每次跟进续期，crm.customer.protect_days）',
  `last_followup_at` DATETIME DEFAULT NULL COMMENT '最近跟进时间（公海释放判定依据）',
  `remark` VARCHAR(500) NOT NULL DEFAULT '',
  `ext_text` TEXT NULL COMMENT '自定义扩展字段（应用层序列化，5.5 无 JSON 类型）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  `deleted_at` DATETIME DEFAULT NULL COMMENT '软删时间（RL-3，进回收站）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_owner_pool` (`owner_id`,`pool_status`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户主档';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_contact` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL COMMENT '所属客户',
  `name` VARCHAR(64) NOT NULL COMMENT '联系人姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号（展示脱敏）',
  `title` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '职务',
  `is_primary` TINYINT NOT NULL DEFAULT 0 COMMENT '主联系人 1/0',
  `remark` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='联系人';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_lead` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL COMMENT '线索编码 XS+yyyyMMdd+4位流水',
  `name` VARCHAR(191) NOT NULL COMMENT '线索主体名称',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号（查重依据，GR-4）',
  `source` VARCHAR(64) NOT NULL COMMENT '来源渠道（必填，字典 lg_lin-crm_dict:lead_source）',
  `intent_level` ENUM('high','mid','low','none') NOT NULL DEFAULT 'mid' COMMENT '意向分级',
  `status` ENUM('unassigned','assigned','following','converted','invalid','recycled') NOT NULL DEFAULT 'unassigned' COMMENT '状态 STM-LEA',
  `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前负责人（0=池内）',
  `recycle_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '回收原因（FR-LEA-06 必填）',
  `converted_customer_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '转化后客户 id（保留跳转链接）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_phone` (`phone`),
  KEY `idx_owner_status` (`owner_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='线索';
