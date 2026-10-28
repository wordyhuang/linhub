-- 林客功能包迁移 003 · 合同与回款：contract / contract_change / receivable_plan / receivable_record
-- 幂等：可重复执行

CREATE TABLE IF NOT EXISTS `lg_lin-crm_contract` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL COMMENT '合同编码 HT+yyyyMMdd+4位流水',
  `customer_id` BIGINT UNSIGNED NOT NULL COMMENT '所属客户',
  `opportunity_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联合机（0=无）',
  `name` VARCHAR(191) NOT NULL COMMENT '合同名称',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '合同额',
  `sign_date` DATE DEFAULT NULL COMMENT '签订日',
  `effective_from` DATE DEFAULT NULL COMMENT '有效期起',
  `effective_to` DATE DEFAULT NULL COMMENT '有效期止',
  `status` ENUM('draft','auditing','effective','settled','terminated') NOT NULL DEFAULT 'draft' COMMENT '状态 STM-CON（⛔OI-006 冻结版）',
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '已回款合计（登记时联动更新）',
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '应收余额 = amount - paid_amount',
  `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '经手人',
  `audit_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批人',
  `audit_opinion` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '审批意见（驳回必填）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_customer_status` (`customer_id`,`status`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_contract_change` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL COMMENT '合同 id',
  `change_type` VARCHAR(32) NOT NULL COMMENT '变更类型（amount/date/term）',
  `old_value` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '变更前值',
  `new_value` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '变更后值',
  `reason` VARCHAR(255) NOT NULL COMMENT '变更原因（必填）',
  `operator_id` BIGINT UNSIGNED NOT NULL COMMENT '操作人',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同变更记录（只增不改，RL-2/FR-CON-07）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_receivable_plan` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL COMMENT '合同 id（仅 effective 状态可维护）',
  `period_no` INT UNSIGNED NOT NULL COMMENT '期次号（1 起）',
  `amount` DECIMAL(12,2) NOT NULL COMMENT '计划金额',
  `plan_date` DATE NOT NULL COMMENT '计划收款日（T-3/T-1/D+1 提醒依据）',
  `received_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '已收金额',
  `status` ENUM('pending','partial','settled') NOT NULL DEFAULT 'pending' COMMENT '期次状态',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contract_period` (`contract_id`,`period_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回款计划（分期）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_receivable_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` BIGINT UNSIGNED NOT NULL COMMENT '回款计划期次 id',
  `contract_id` BIGINT UNSIGNED NOT NULL COMMENT '合同 id（冗余，报表用）',
  `amount` DECIMAL(12,2) NOT NULL COMMENT '本次实收金额（部分收款允许）',
  `paid_at` DATE NOT NULL COMMENT '实收日期（RL-2 禁改）',
  `trade_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '外部流水号（幂等键唯一，BND-08 同源设计）',
  `operator_id` BIGINT UNSIGNED NOT NULL COMMENT '登记人（财务）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回款记录（RL-2：金额/日期禁改）';
