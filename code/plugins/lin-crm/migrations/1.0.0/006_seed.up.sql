-- 林客功能包迁移 006 · 种子数据（INSERT IGNORE 幂等）
-- 注意：INSERT IGNORE 依赖唯一键（uk_type_key / 主键 id=1），重复执行不产生副作用

-- 公海规则默认行（租户单行，id=1；保护期 30 / 上限 50 / 02:00，OI-002 评审定稿）
INSERT IGNORE INTO `lg_lin-crm_pool_rule` (`id`,`protect_days`,`private_limit`,`release_run_at`)
VALUES (1, 30, 50, '02:00');

-- 字典种子：客户来源
INSERT IGNORE INTO `lg_lin-crm_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`) VALUES
('source','ad','广告投放',1),
('source','expo','展会',2),
('source','referral','转介绍',3),
('source','online','线上咨询',4),
('source','walkin','陌拜/上门',5);

-- 字典种子：线索来源渠道
INSERT IGNORE INTO `lg_lin-crm_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`) VALUES
('lead_source','ad','广告投放',1),
('lead_source','expo','展会名片',2),
('lead_source','referral','客户转介绍',3),
('lead_source','online','官网/线上',4),
('lead_source','phonein','来电咨询',5);

-- 字典种子：客户等级
INSERT IGNORE INTO `lg_lin-crm_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`) VALUES
('level','key','重点客户',1),
('level','normal','普通客户',2),
('level','small','小微客户',3);

-- 字典种子：客户行业
INSERT IGNORE INTO `lg_lin-crm_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`) VALUES
('industry','manufacture','制造业',1),
('industry','trade','商贸流通',2),
('industry','service','服务业',3),
('industry','construction','建筑工程',4),
('industry','other','其他',5);
