-- 007_drop_recycle.up.sql · 回收站接入平台 recyclebin 服务，废弃自建表
-- 历史软删快照数据已随功能升级由平台服务接管（此前条目不再迁移，源行仍为软删态可正常过滤）。
DROP TABLE IF EXISTS `lg_lin-crm_recycle_bin`;
