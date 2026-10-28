<?php

declare(strict_types=1);

namespace linhub\host\console;

use linhub\host\services\attachment\AttachmentService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;

/**
 * 附件过期/孤儿清理命令（AT-6/AT-12）。
 *
 * 由外部调度器按 .env 的 ATTACH_CLEAN_CRON 周期调用；
 * ATTACH_CLEAN_ENABLED=1 时直接执行，未启用时仅 --force 强制执行。
 */
final class AttachmentCleanCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('linhub:attachment-clean')
            ->addOption('force', 'f', Option::VALUE_NONE, '忽略 ATTACH_CLEAN_ENABLED 开关，强制执行一次清理')
            ->setDescription('清理过期附件与孤儿文件（平台附件服务 AT-6/AT-12）')
            ->setHelp(
                "默认按 .env 的 ATTACH_CLEAN_ENABLED 决定是否执行；\n"
                . "加 --force 可在未启用时手动执行。\n"
                . "建议由系统计划任务按 ATTACH_CLEAN_CRON 表达式调度，例如：\n"
                . "  php think linhub:attachment-clean"
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $enabled = ((string) Env::get('ATTACH_CLEAN_ENABLED', '0')) === '1';
        $forced = (bool) $input->getOption('force');

        if (!$enabled && !$forced) {
            $output->writeln('<info>附件定时清理未启用（ATTACH_CLEAN_ENABLED=0），本次跳过。</info>');
            $output->writeln('<comment>如需强制执行，请使用 --force 选项，或在 .env 中启用 ATTACH_CLEAN_ENABLED。</comment>');
            return 0;
        }

        $output->writeln('<info>开始执行附件清理（触发方式：cron）...</info>');
        try {
            $result = (new AttachmentService())->clean('cron', '');
        } catch (\Throwable $e) {
            $output->error('附件清理失败：' . $e->getMessage());
            return 1;
        }

        $output->writeln('<info>清理完成：</info>');
        $output->writeln('  扫描对象数：' . (int) ($result['scanned'] ?? 0));
        $output->writeln('  清理数量：' . (int) ($result['deleted'] ?? 0)
            . '（过期附件 ' . (int) ($result['expired'] ?? 0)
            . '，孤儿文件 ' . (int) ($result['orphan_files'] ?? 0) . '）');
        $output->writeln('  释放空间：' . $this->formatBytes((int) ($result['bytes_freed'] ?? 0)));
        $output->writeln('  执行时间：' . (string) ($result['created_at'] ?? ''));
        foreach ((array) ($result['detail'] ?? []) as $line) {
            $output->writeln('<comment>  ' . $line . '</comment>');
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $index), 2) . ' ' . $units[$index];
    }
}
