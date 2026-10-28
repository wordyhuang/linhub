<?php
// UAIS 统一身份认证配置（T0.3 / C3 C1）
// 凭证一律从 .env 读取，绝不写死在源码中（安全红线）。
return [
    'base_url'  => (string) env('UAIS_BASE_URL', 'https://uais.qianbiaodashuju.cn/'),
    'app_key'   => (string) env('UAIS_APP_KEY', ''),
    'app_secret' => (string) env('UAIS_APP_SECRET', ''),
    // 判定"超级管理员"所需的 tag 取值约定（T0.3 AC：识别超级管理员 tag）
    'admin_tag' => (string) env('UAIS_ADMIN_TAG', 'LinHub SuperAdministrator'),
];