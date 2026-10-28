<?php
// admin 应用中间件定义文件
return [
    // 中后台统一登录校验（白名单：auth/login、auth/doLogin）
    \app\admin\middleware\RequireLogin::class,
];
