<?php
// 临时脚本：重新登记 lin-crm 菜单（menus.php 变更后刷新 registry 快照）
require __DIR__ . '/vendor/autoload.php';

use linhub\host\integration\IntegrationManager;

$registryFile = __DIR__ . '/data/integration/registry.json';
$loadRoot     = dirname(__DIR__) . '/plugins';

$handler = IntegrationManager::installHandler($loadRoot, $registryFile);
$handler(['short' => 'lin-crm']);

// 回读验证
$reg = json_decode(file_get_contents($registryFile), true);
$items = $reg['lin-crm']['menus'] ?? [];
echo "lin-crm 菜单条数: " . count($items) . PHP_EOL;
foreach ($items as $it) {
    printf("%-24s %-14s route=%-28s valid=%s\n", $it['id'], $it['title'], $it['route'], var_export($it['valid'], true));
}
