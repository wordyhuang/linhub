-- 006_seed.down.sql · 回滚种子数据（仅删除 up 写入的固定键，不误删业务数据）
DELETE FROM `lg_lin-crm_pool_rule` WHERE `id` = 1;

DELETE FROM `lg_lin-crm_dict`
WHERE (`dict_type`='source'    AND `dict_key` IN ('ad','expo','referral','online','walkin'))
   OR (`dict_type`='lead_source' AND `dict_key` IN ('ad','expo','referral','online','phonein'))
   OR (`dict_type`='level'     AND `dict_key` IN ('key','normal','small'))
   OR (`dict_type`='industry'  AND `dict_key` IN ('manufacture','trade','service','construction','other'));
