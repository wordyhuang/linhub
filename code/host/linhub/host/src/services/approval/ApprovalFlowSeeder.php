<?php

declare(strict_types=1);

namespace linhub\host\services\approval;

/**
 * 包声明式审批流程导入器（resource/approvals/*.json → defineFlow）。
 *
 * 包根固定目录 resource/approvals/，每个 *.json 文件即一个流程定义对象（单文件单流程），
 * 无需在 manifest 声明。语义（较翻译种子保守）：
 *  - 安装/升级统一 upsert：同 flow_key 覆盖定义并 version+1；
 *  - 不删除包内已移除的旧流程定义、卸载不清理 —— 在途/历史审批单据的节点解析依赖流程定义，删除会破坏解析。
 *
 * P6 容错：目录缺失、文件不可读、坏 JSON、非单流程对象形式（数组多流程/空）、单文件注册失败
 * —— 一律收集为 warning 跳过，不中断安装/升级。
 */
final class ApprovalFlowSeeder
{
    /** 包根下的流程声明目录名（固定约定）。 */
    public const FLOW_DIR = 'resource/approvals';

    private ApprovalService $service;

    public function __construct(?ApprovalService $service = null)
    {
        $this->service = $service ?? new ApprovalService();
    }

    /**
     * 读取包内 resource/approvals/ 下全部流程声明文件。
     *
     * @param string $packageDir 包根目录
     * @return array{flows: list<array{file: string, flow: array}>, warnings: list<string>}
     */
    public function readFlows(string $packageDir): array
    {
        $flows = [];
        $warnings = [];
        $dir = rtrim($packageDir, '/\\') . '/' . self::FLOW_DIR;
        if (!is_dir($dir)) {
            return ['flows' => $flows, 'warnings' => $warnings];
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || trim($raw) === '') {
                $warnings[] = '审批流程文件不可读（已跳过）：' . basename($file);
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || $this->isList($data)) {
                $warnings[] = '审批流程文件不是单流程 JSON 对象（已跳过）：' . basename($file);
                continue;
            }
            $flows[] = ['file' => basename($file), 'flow' => $data];
        }
        return ['flows' => $flows, 'warnings' => $warnings];
    }

    /**
     * 安装/升级期导入：逐文件 defineFlow upsert（source=package，host 代包注册合法）。
     *
     * @return array{imported: int, warnings: list<string>}
     */
    public function importPackage(string $short, string $packageDir): array
    {
        if ($short === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $short)) {
            throw new \InvalidArgumentException('非法包名，无法导入审批流程声明');
        }
        $read = $this->readFlows($packageDir);
        $warnings = $read['warnings'];
        $imported = 0;
        foreach ($read['flows'] as $item) {
            try {
                $this->service->defineFlow($short, $item['flow'], 'package');
                $imported++;
            } catch (\Throwable $e) {
                $warnings[] = '审批流程文件注册失败 ' . $item['file'] . '：' . $e->getMessage();
            }
        }
        return ['imported' => $imported, 'warnings' => $warnings];
    }

    /** 顺序数组判定（不依赖 array_is_list，保持库内写法一致）。 */
    private function isList(array $data): bool
    {
        if ($data === []) {
            return true;
        }
        return array_keys($data) === range(0, count($data) - 1);
    }
}
