<?php

declare(strict_types=1);

namespace app\admin\controller;

use linhub\capability\auth\Identity;
use linhub\host\services\dictionary\DictionaryService;

/**
 * 数据字典超管全局页（DD-9）—— admin 应用，仅超管
 *
 *  - GET  /admin/dict_admin/index   全局字典页空壳
 *  - GET  /admin/dict_admin/rows    全命名空间分页检索 JSON（namespace/keyword/is_public）
 *  - GET  /admin/dict_admin/detail  单条字典详情（含 value_parsed 与原始值）
 *  - GET  /admin/dict_admin/options 命名空间选项（stats 聚合）
 *  - POST /admin/dict_admin/save    修改字典条目（值/备注，按 value_type 差异化解析）
 *
 * 检索与详情为超管只读；save 按值类型（json/string/int/float/bool/null）解析校验后落库，
 * 每次修改审计留痕（dict.update）。
 */
class DictAdmin extends AdminController
{
    private function service(): DictionaryService
    {
        return new DictionaryService();
    }

    public function index()
    {
        $gate = $this->gate();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->app->view->fetch('dict_admin/index');
    }

    /** JSON：全量字典分页（超管视角 asAdmin=true，跨命名空间可见私有项）。 */
    public function rows()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $req = $this->request;
        $filter = [];
        foreach (['namespace', 'keyword', 'is_public'] as $k) {
            $v = trim((string) $req->get($k, ''));
            if ($v !== '') {
                $filter[$k] = $v;
            }
        }
        $page = max(1, (int) $req->get('page', 1));
        $size = max(1, min(200, (int) $req->get('size', 20)));

        // package 参数仅用于非超管视角的归属隔离；超管传空 + asAdmin=true 看全部
        $result = $this->service()->search('', $filter, $page, $size, true);
        return $this->jsonOk([
            'list'        => (array) ($result['items'] ?? []),
            'total'       => (int) ($result['total'] ?? 0),
            'currentPage' => (int) ($result['page'] ?? $page),
            'pageSize'    => (int) ($result['size'] ?? $size),
            'stats'       => $this->service()->stats(),
        ]);
    }

    /** JSON：单条字典详情（超管视角）。 */
    public function detail()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $namespace = trim((string) $this->request->get('namespace', ''));
        $key       = trim((string) $this->request->get('key', ''));
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 key');
        }
        $entry = $this->service()->entry('', $namespace, $key, true);
        if ($entry === null) {
            return $this->jsonFail('字典项不存在', 404);
        }
        $this->audit($gate, 'dict.detail.view', $namespace . '/' . $key);
        return $this->jsonOk($entry);
    }

    /** JSON：命名空间选项与汇总（筛选用）。 */
    public function options()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        return $this->jsonOk($this->service()->stats());
    }

    /** 修改字典条目（值/备注）：按 value_type 解析校验后落库，保留原公开性。 */
    public function save()
    {
        $gate = $this->gateForJson();
        if (!$gate instanceof Identity) {
            return $gate;
        }
        $namespace = trim((string) $this->request->post('namespace', ''));
        $key       = trim((string) $this->request->post('key', ''));
        $valueType = trim((string) $this->request->post('value_type', ''));
        $valueStr  = (string) $this->request->post('value', '');
        $remark    = trim((string) $this->request->post('remark', ''));
        if ($namespace === '' || $key === '') {
            return $this->jsonFail('缺少 namespace 或 key');
        }
        if (!in_array($valueType, ['json', 'string', 'int', 'float', 'bool', 'null'], true)) {
            return $this->jsonFail('不支持的字典值类型：' . $valueType);
        }
        $svc = $this->service();
        $entry = $svc->entry('', $namespace, $key, true);
        if ($entry === null) {
            return $this->jsonFail('字典项不存在', 404);
        }
        try {
            $value = $this->parseValue($valueType, $valueStr);
            $svc->put($namespace, $key, $value, [
                'remark'    => $remark,
                'is_public' => (int) ($entry['is_public'] ?? 0) === 1, // 保留原公开性
            ]);
            $this->audit($gate, 'dict.update', $namespace . '/' . $key);
            return $this->jsonOk(null, '字典项已更新');
        } catch (\Throwable $e) {
            return $this->jsonFail('保存失败：' . $e->getMessage());
        }
    }

    /** 按值类型解析前端提交的原始字符串为实际 PHP 值。 */
    private function parseValue(string $type, string $raw): mixed
    {
        return match ($type) {
            'json'  => $this->parseJson($raw),
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'bool'  => strtolower($raw) === '1' || strtolower($raw) === 'true' || strtolower($raw) === 'on'
                ? true
                : (strtolower($raw) === '0' || strtolower($raw) === 'false' || strtolower($raw) === 'off' || $raw === ''
                    ? false
                    : throw new \InvalidArgumentException('布尔值格式不合法：' . $raw)),
            'null'  => null,
            default => $raw,
        };
    }

    /** JSON 类型：校验合法并返回解码后的值。 */
    private function parseJson(string $raw): mixed
    {
        $v = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON 格式不合法：' . json_last_error_msg());
        }
        return $v;
    }

    /** 超管查看留痕（管控轨迹），失败不阻断。 */
    private function audit(Identity $identity, string $action, string $target): void
    {
        try {
            $this->bootstrap->auditTrail()->log($action, $target, $identity->id, ['via' => 'admin-gui']);
        } catch (\Throwable $e) {
        }
    }
}
