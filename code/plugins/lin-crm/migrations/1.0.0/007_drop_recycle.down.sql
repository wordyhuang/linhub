-- 007_drop_recycle.down.sql · 恢复自建回收站表结构（仅结构，不含历史数据）
CREATE TABLE IF NOT EXISTS `lg_lin-crm_recycle_bin` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `src_table` VARCHAR(64) NOT NULL COMMENT '来源表名（lg_lin-crm_*）',
  `src_id` BIGINT UNSIGNED NOT NULL COMMENT '来源行 id',
  `payload` TEXT NULL COMMENT '软删前行数据快照（应用层序列化）',
  `expired_at` DATETIME NOT NULL COMMENT '过期时间（30 天，crm.recycle.retention_days）',
  `operator_id` BIGINT UNSIGNED NOT NULL COMMENT '删除操作人',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_src` (`src_table`,`src_id`),
  KEY `idx_expired` (`expired_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回收站（RL-3：30 天保留，仅管理员可恢复/彻底删除）';
