<?php
/**
 * 林客功能包公共函数（composer files 自动加载）
 * 宿主适配函数 lg_host_* 由宿主注入；本包内所有调用均以 function_exists 守卫，未注入时按最保守语义运行。
 */

/** 统一时间格式（GR-6 租户时区 Asia/Shanghai） */
function now_fmt(): string
{
    return date('Y-m-d H:i:s');
}

/* ── 宿主适配函数契约（由宿主功能包引导层提供实现，此处不定义） ──
 * lg_host_perm_check(string $perm): bool          功能权限校验
 * lg_host_data_scope(): string                    数据行权限四档 all/dept_sub/dept/self
 * lg_host_dept_user_ids(string $mode): int[]      部门可见用户 id 集
 * 实现缺省时：checkPerm 拒绝（fail-closed）、scope 收敛为仅本人 —— 符合 NFR-SEC-02 最保守要求。
 */
