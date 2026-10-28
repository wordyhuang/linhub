-- 林客功能包迁移 002 · 商机表：opportunity / opportunity_member
-- 幂等：可重复执行

CREATE TABLE IF NOT EXISTS `lg_lin-crm_opportunity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL COMMENT '商机编码 SJ+yyyyMMdd+4位流水',
  `customer_id` BIGINT UNSIGNED NOT NULL COMMENT '所属客户（必挂，无客户禁止建档）',
  `name` VARCHAR(191) NOT NULL COMMENT '商机名称',
  `stage` ENUM('initial','confirm','proposal','negotiate') NOT NULL DEFAULT 'initial' COMMENT '漏斗阶段（单向推进，回退留痕）',
  `status` ENUM('open','won','lost') NOT NULL DEFAULT 'open' COMMENT '主状态 STM-OPP（lost 可重开回 open）',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '预计金额',
  `expect_close` DATE DEFAULT NULL COMMENT '预计成交日',
  `win_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT '赢率快照（进入阶段时按 crm.opportunity.win_rates 落值）',
  `result_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '赢单/输单原因（终态必填）',
  `competitor` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '竞争对手（FR-OPP-06 P2 预留列）',
  `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '负责人',
  `stage_changed_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01' COMMENT '进入当前阶段时间（停滞预警计算依据）',
  `version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '乐观锁版本号（BND-05 并发推进/回退）',
  `closed_at` DATETIME DEFAULT NULL COMMENT '落终态时间',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_customer_stage` (`customer_id`,`stage`),
  KEY `idx_owner_status` (`owner_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商机';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_opportunity_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `opportunity_id` BIGINT UNSIGNED NOT NULL COMMENT '商机 id',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '参与人用户 id',
  `role` VARCHAR(32) NOT NULL DEFAULT 'member' COMMENT '角色（owner/member）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_opp_user` (`opportunity_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商机参与人';
