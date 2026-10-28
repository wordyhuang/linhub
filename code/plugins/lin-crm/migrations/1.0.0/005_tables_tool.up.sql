-- 林客功能包迁移 005 · 工具表：pool_rule / attachment / dict
-- 幂等：可重复执行
-- 注：回收站不再自建（软删快照托管平台 recyclebin 服务 lg_mgt_recycle_bin），
--     历史表由 007_drop_recycle.up.sql 清除。

CREATE TABLE IF NOT EXISTS `lg_lin-crm_pool_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `protect_days` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT '保护期天数（OI-002：默认 30，评审定稿）',
  `private_limit` INT UNSIGNED NOT NULL DEFAULT 50 COMMENT '私海持有上限（OI-002：默认 50）',
  `release_run_at` VARCHAR(8) NOT NULL DEFAULT '02:00' COMMENT '释放检查时点（AC-CUS-02-1）',
  `updated_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公海规则（租户单行，默认行见 006_seed）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_attachment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `biz_type` VARCHAR(32) NOT NULL COMMENT '业务类型（contract_scan/proposal/import_receipt）',
  `biz_id` BIGINT UNSIGNED NOT NULL COMMENT '业务 id',
  `file_name` VARCHAR(191) NOT NULL COMMENT '原始文件名',
  `file_path` VARCHAR(255) NOT NULL COMMENT '存储路径（宿主文件服务）',
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字节数',
  `uploaded_by` BIGINT UNSIGNED NOT NULL COMMENT '上传人',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_biz` (`biz_type`,`biz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='附件（合同扫描件/报价附件/导入回执）';

CREATE TABLE IF NOT EXISTS `lg_lin-crm_dict` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dict_type` VARCHAR(64) NOT NULL COMMENT '字典类型（source/level/industry/lead_source）',
  `dict_key` VARCHAR(64) NOT NULL COMMENT '枚举键',
  `dict_value` VARCHAR(191) NOT NULL COMMENT '显示名',
  `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '启用 1/停用 0',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_key` (`dict_type`,`dict_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典配置（FR-TOOL-03 租户级维护）';
