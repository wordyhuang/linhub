<?php
// admin 应用中间件定义文件
return [
    // 平台统一登录校验（宿主公共实现，功能包路由共用同一中间件；白名单：auth/login、auth/doLogin）
    \linhub\host\support\middleware\RequireLogin::class,
];
