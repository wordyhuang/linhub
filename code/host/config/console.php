<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'linhub:attachment-clean' => \linhub\host\console\AttachmentCleanCommand::class,
    ],
];
