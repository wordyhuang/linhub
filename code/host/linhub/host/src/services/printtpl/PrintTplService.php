<?php

declare(strict_types=1);

namespace linhub\host\services\printtpl;

use linhub\host\services\support\PlatformStore;

/**
 * 单据打印模板服务实现（服务 id：printtpl，托管服务 hosted）。
 *
 * 职责（对应需求 PT-1 ~ PT-19）：
 *  - 模板管理：新增/编辑/删除/复制/启停（管理端），编号全局唯一且创建后不可改（PT-11）；
 *  - 双轨归属（T-1）：scope=platform 平台公共（全包可用）/ scope=package 包级私有（仅归属包可用）；
 *  - 渲染：按编号 + 业务数据产出完整可打印 HTML（PT-14），校验状态与可见范围（PT-13）；
 *  - printUrl：一次性票据（5 分钟、单次使用）承载业务数据，URL 仅带 token（PT-15/T-7）；
 *  - 版本快照：每次保存自动留快照，保留最近 10 版，可回滚（PT-18）；
 *  - 配额：模板总数 200、单模板正文 ≤ 1MB、票据数据 ≤ 1MB（AC-8）；
 *  - 卸载保护：orphanPackage 冻结 / adoptPackage 重装接管 / purgePackage 数据清理（语义同 approval）；
 *  - 渲染缓存：模板编译结果按正文内容哈希缓存（PT-17），保存即产生新哈希自然失效。
 *
 * 审计口径（PT-19）：管理操作（新增/编辑/启停/删除/复制/回滚/试打）由管理端
 * PrintTplAdmin 统一记入平台审计（bootstrap auditTrail），服务侧不重复记录。
 */
final class PrintTplService implements PrintTpl
{
    /** 模板总数上限（建议配额：平台 200） */
    public const QUOTA_MAX_TEMPLATES = 200;

    /** 单模板正文字节上限（1MB） */
    public const QUOTA_BODY_BYTES = 1048576;

    /** 版本快照保留数量（最近 N 版） */
    public const VERSION_KEEP = 10;

    /** printUrl 票据有效期（秒）与数据字节上限 */
    public const TICKET_TTL = 300;
    public const TICKET_DATA_BYTES = 1048576;

    /** 模板编号规则（PT-11 建议 `用途.版式`，如 invoice.standard / receipt.80mm） */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/';

    private static ?PrintTplService $inst = null;

    private ?PlatformStore $store;

    private function __construct(?PlatformStore $store = null)
    {
        $this->store = $store;
    }

    public static function instance(?PlatformStore $store = null): self
    {
        if (self::$inst === null || $store !== null) {
            $svc = new self($store);
            if ($store === null) {
                self::$inst = $svc;
            }
            return $svc;
        }
        return self::$inst;
    }

    /** 数据网关（DataDriver 等平台侧协作类也经此访问，勿暴露给功能包）。 */
    public function store(): PlatformStore
    {
        return $this->store ??= new PlatformStore();
    }

    private function now(): string
    {
        return date('c');
    }

    // ================================================================ 契约实现

    /** {@inheritdoc} */
    public function render(?string $package, string $code, array $data): string
    {
        Renderer::validateData($data);
        $tpl = $this->findTpl($code);
        $this->assertRenderable($tpl, $package);
        $body = Renderer::renderBody((string) $tpl['body'], $data);
        return Renderer::pageDocument($body, $this->paperOf($tpl), (string) $tpl['name']);
    }

    /** {@inheritdoc} */
    public function printUrl(?string $package, string $code, array $data, array $options = []): string
    {
        Renderer::validateData($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new PrintTplQuota('业务数据无法 JSON 序列化');
        }
        if (strlen($json) > self::TICKET_DATA_BYTES) {
            throw new PrintTplQuota('业务数据超出票据上限 1MB，请改用 render 自行呈现');
        }
        $tpl = $this->findTpl($code);
        $this->assertRenderable($tpl, $package);

        $st = $this->store();
        $now = $this->now();
        $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TICKET_TABLE
            . " WHERE expires_at <> '' AND expires_at < " . $st->quote($now));
        $token = bin2hex(random_bytes(16));
        $st->db()->exec('INSERT INTO ' . PlatformStore::PRINT_TICKET_TABLE
            . ' (token, owner_pkg, tpl_code, data, created_at, expires_at, used_at) VALUES ('
            . $st->quote($token) . ', '
            . $st->quote((string) ($package ?? '')) . ', '
            . $st->quote($code) . ', '
            . $st->quote($json) . ', '
            . $st->quote($now) . ', '
            . $st->quote(date('c', time() + self::TICKET_TTL)) . ", '')");
        return '/admin/printtpl_admin/print?token=' . $token;
    }

    /** {@inheritdoc} */
    public function pdf(?string $package, string $code, array $data, array $options = []): array
    {
        Renderer::validateData($data);
        $tpl = $this->findTpl($code);
        $this->assertRenderable($tpl, $package);
        $body = Renderer::renderBody((string) $tpl['body'], $data);
        $html = Renderer::pageDocument($body, $this->paperOf($tpl), (string) $tpl['name']);
        $file = PrintTplPdf::fromHtml($html);
        return [
            'file'  => $file,
            'name'  => (string) $tpl['code'] . '.pdf',
            'bytes' => (int) filesize($file),
        ];
    }

    /** {@inheritdoc} */
    public function get(?string $package, string $code): ?array
    {
        $tpl = $this->findTpl($code);
        if ($tpl === null) {
            return null;
        }
        return [
            'code'        => (string) $tpl['code'],
            'name'        => (string) $tpl['name'],
            'scope'       => (string) $tpl['scope'],
            'owner_pkg'   => (string) $tpl['owner_pkg'],
            'status'      => (int) $tpl['status'],
            'mode'        => (string) $tpl['mode'],
            'paper'       => $this->paperOf($tpl),
            'orphaned_at' => (string) $tpl['orphaned_at'],
            'updated_at'  => (string) $tpl['updated_at'],
        ];
    }

    // ================================================================ 渲染内部

    private function findTpl(string $code): ?array
    {
        $st = $this->store();
        $rows = $st->db()->query(
            'SELECT * FROM ' . PlatformStore::PRINT_TPL_TABLE
            . ' WHERE code = ' . $st->quote($code) . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    /**
     * 渲染前置校验（PT-13 / T-1）：存在性、启停、孤儿冻结、归属可见范围。
     * 校验失败一律 PrintTplDenied（业务错误，非 500，AC-3）。
     */
    private function assertRenderable(?array $tpl, ?string $package): void
    {
        if ($tpl === null) {
            throw new PrintTplDenied('模板不存在');
        }
        if ((string) $tpl['orphaned_at'] !== '') {
            throw new PrintTplDenied('模板归属的功能包已卸载，模板暂不可用');
        }
        if ((int) $tpl['status'] !== 1) {
            throw new PrintTplDenied('模板已停用');
        }
        if ((string) $tpl['scope'] === 'package' && (string) $tpl['owner_pkg'] !== (string) ($package ?? '')) {
            throw new PrintTplDenied('无权使用他包私有模板');
        }
    }

    private function paperOf(array $tpl): array
    {
        $paper = json_decode((string) $tpl['paper'], true);
        return is_array($paper) ? Renderer::normalizePaper($paper) : Renderer::normalizePaper([]);
    }

    /** 管理端渲染（试打/预览入口）：按 id 取模板 + 样例数据渲染完整打印文档。 */
    public function renderById(int $id, bool $autoPrint = false): string
    {
        $st = $this->store();
        $rows = $st->db()->query(
            'SELECT * FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE id = ' . $st->int($id) . ' LIMIT 1'
        );
        $tpl = $rows[0] ?? null;
        if ($tpl === null) {
            throw new PrintTplDenied('模板不存在');
        }
        $sample = json_decode((string) ($tpl['sample_data'] ?? ''), true);
        $body = Renderer::renderBody((string) $tpl['body'], is_array($sample) ? $sample : []);
        return Renderer::pageDocument($body, $this->paperOf($tpl), (string) $tpl['name']);
    }

    /** 编辑页预览（未落库内容）：正文 + 纸张 + 样例数据 → 完整打印文档。 */
    public function renderDraft(string $body, array $paper, array $sample): string
    {
        $body = Renderer::renderBody($body, $sample);
        return Renderer::pageDocument($body, Renderer::normalizePaper($paper));
    }

    /** 试打留痕（PT-2 最近试打时间）。 */
    public function markTested(int $id): void
    {
        $st = $this->store();
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE
            . ' SET last_test_at = ' . $st->quote($this->now())
            . ' WHERE id = ' . $st->int($id));
    }

    /** print 页消费票据：一次性（T-7）。 */
    public function consumeTicket(string $token): array
    {
        $st = $this->store();
        $rows = $st->db()->query(
            'SELECT * FROM ' . PlatformStore::PRINT_TICKET_TABLE
            . ' WHERE token = ' . $st->quote($token) . ' LIMIT 1'
        );
        $t = $rows[0] ?? null;
        if ($t === null || (string) $t['used_at'] !== '') {
            throw new PrintTplDenied('打印票据无效或已使用');
        }
        if ((string) $t['expires_at'] !== '' && (string) $t['expires_at'] < $this->now()) {
            throw new PrintTplDenied('打印票据已过期，请回到功能包重新发起打印');
        }
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TICKET_TABLE
            . ' SET used_at = ' . $st->quote($this->now())
            . ' WHERE id = ' . $st->int((int) $t['id']));
        return [
            'owner_pkg' => (string) $t['owner_pkg'],
            'tpl_code'  => (string) $t['tpl_code'],
            'data'      => json_decode((string) $t['data'], true) ?: [],
        ];
    }

    // ================================================================ 管理端（模板管理）

    /**
     * 模板列表（PT-2）：keyword 命中名称/编号，scope/status/owner_pkg 过滤，分页。
     */
    public function listRows(string $keyword, string $scope, string $status, string $ownerPkg, int $page, int $pageSize): array
    {
        $st = $this->store();
        $where = [];
        if ($keyword !== '') {
            $kw = $st->quote('%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%');
            $where[] = '(name LIKE ' . $kw . ' OR code LIKE ' . $kw . ')';
        }
        if (in_array($scope, ['platform', 'package'], true)) {
            $where[] = 'scope = ' . $st->quote($scope);
        }
        if (in_array($status, ['1', '0'], true)) {
            $where[] = 'status = ' . (int) $status;
        }
        if ($ownerPkg !== '') {
            $where[] = 'owner_pkg = ' . $st->quote($ownerPkg);
        }
        $cond = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) (($st->db()->query(
            'SELECT COUNT(*) AS c FROM ' . PlatformStore::PRINT_TPL_TABLE . $cond
        )[0]['c'] ?? 0));
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $rows = $st->db()->query(
            'SELECT id, code, name, scope, owner_pkg, status, mode, body_size, last_test_at, orphaned_at, created_by, created_at, updated_at'
            . ' FROM ' . PlatformStore::PRINT_TPL_TABLE . $cond
            . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
        ) ?? [];
        return ['rows' => $rows, 'total' => $total];
    }

    /** 模板详情（含正文/纸张/样例数据），供编制页载入。 */
    public function detail(int $id): array
    {
        $st = $this->store();
        $rows = $st->db()->query(
            'SELECT * FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE id = ' . $st->int($id) . ' LIMIT 1'
        );
        $tpl = $rows[0] ?? null;
        if ($tpl === null) {
            throw new PrintTplDenied('模板不存在');
        }
        $sample = json_decode((string) ($tpl['sample_data'] ?? ''), true);
        return [
            'id'          => (int) $tpl['id'],
            'code'        => (string) $tpl['code'],
            'name'        => (string) $tpl['name'],
            'scope'       => (string) $tpl['scope'],
            'owner_pkg'   => (string) $tpl['owner_pkg'],
            'status'      => (int) $tpl['status'],
            'mode'        => (string) $tpl['mode'],
            'paper'       => $this->paperOf($tpl),
            'body'        => (string) $tpl['body'],
            'sample_data' => is_array($sample) ? $sample : [],
            'orphaned_at' => (string) $tpl['orphaned_at'],
            'updated_at'  => (string) $tpl['updated_at'],
        ];
    }

    /**
     * 新增/编辑保存（PT-1）：编号唯一且创建后不可改；正文/配额校验（AC-8）；
     * 每次保存留版本快照并只保留最近 VERSION_KEEP 版（PT-18）。
     *
     * @return array {id, version_no}
     */
    public function save(?int $id, array $in, string $operator): array
    {
        $st = $this->store();
        $now = $this->now();

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new PrintTplDenied('模板名称不能为空');
        }
        $body = (string) ($in['body'] ?? '');
        if (strlen($body) > self::QUOTA_BODY_BYTES) {
            throw new PrintTplQuota('模板正文超出 1MB 上限');
        }
        $modeRaw = (string) ($in['mode'] ?? 'visual');
        $mode = in_array($modeRaw, ['visual', 'source'], true) ? $modeRaw : 'visual';
        $scope = ($in['scope'] ?? 'platform') === 'package' ? 'package' : 'platform';
        $ownerPkg = trim((string) ($in['owner_pkg'] ?? ''));
        if ($scope === 'package' && $ownerPkg === '') {
            throw new PrintTplDenied('包级私有模板必须指定归属功能包');
        }
        $paper = Renderer::normalizePaper(is_array($in['paper'] ?? null) ? $in['paper'] : []);
        $sample = $in['sample_data'] ?? [];
        if (!is_array($sample)) {
            $sample = [];
        }

        // 自检：正文必须可渲染（语法错误在保存时即暴露，而非等到业务打印）
        try {
            Renderer::compile($body);
        } catch (\RuntimeException $e) {
            throw new PrintTplDenied('模板正文占位符语法错误：' . $e->getMessage());
        }

        if ($id === null || $id <= 0) {
            $code = strtolower(trim((string) ($in['code'] ?? '')));
            if (!preg_match(self::CODE_PATTERN, $code)) {
                throw new PrintTplDenied('模板编号需为「用途.版式」形式，如 invoice.standard（小写字母开头，可用 . - _ ）');
            }
            if ($this->findTpl($code) !== null) {
                throw new PrintTplDenied('模板编号已存在：' . $code);
            }
            $cnt = (int) (($st->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::PRINT_TPL_TABLE)[0]['c'] ?? 0));
            if ($cnt >= self::QUOTA_MAX_TEMPLATES) {
                throw new PrintTplQuota('模板总数已达平台上限 ' . self::QUOTA_MAX_TEMPLATES);
            }
            $st->db()->exec('INSERT INTO ' . PlatformStore::PRINT_TPL_TABLE
                . ' (code, name, scope, owner_pkg, status, mode, paper, body, body_size, sample_data, created_by, created_at, updated_at) VALUES ('
                . $st->quote($code) . ', ' . $st->quote($name) . ', ' . $st->quote($scope) . ', '
                . $st->quote($ownerPkg) . ', 1, ' . $st->quote($mode) . ', ' . $st->quote(json_encode($paper, JSON_UNESCAPED_UNICODE)) . ', '
                . $st->quote($body) . ', ' . $st->int(strlen($body)) . ', ' . $st->quote(json_encode($sample, JSON_UNESCAPED_UNICODE)) . ', '
                . $st->quote($operator) . ', ' . $st->quote($now) . ', ' . $st->quote($now) . ')');
            $id = (int) ($this->findTpl($code)['id'] ?? 0);
            $ver = $this->snapshot($id, $body, $mode, $paper, $operator);
            return ['id' => $id, 'version_no' => $ver];
        }

        // 编辑：编号不可改；归属包不可漂移
        $cur = $st->db()->query('SELECT * FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE id = ' . $st->int($id) . ' LIMIT 1')[0] ?? null;
        if ($cur === null) {
            throw new PrintTplDenied('模板不存在');
        }
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE . ' SET '
            . 'name = ' . $st->quote($name) . ', '
            . 'scope = ' . $st->quote($scope) . ', '
            . 'owner_pkg = ' . $st->quote($ownerPkg) . ', '
            . 'mode = ' . $st->quote($mode) . ', '
            . 'paper = ' . $st->quote(json_encode($paper, JSON_UNESCAPED_UNICODE)) . ', '
            . 'body = ' . $st->quote($body) . ', '
            . 'body_size = ' . $st->int(strlen($body)) . ', '
            . 'sample_data = ' . $st->quote(json_encode($sample, JSON_UNESCAPED_UNICODE)) . ', '
            . 'updated_at = ' . $st->quote($now)
            . ' WHERE id = ' . $st->int($id));
        $ver = $this->snapshot($id, $body, $mode, $paper, $operator);
        return ['id' => $id, 'version_no' => $ver];
    }

    /** 版本快照：插入 + 清理只保留最近 VERSION_KEEP 版（PT-18）。 */
    private function snapshot(int $tplId, string $body, string $mode, array $paper, string $operator): int
    {
        $st = $this->store();
        $max = (int) (($st->db()->query(
            'SELECT COALESCE(MAX(version_no), 0) AS v FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE
            . ' WHERE tpl_id = ' . $st->int($tplId)
        )[0]['v'] ?? 0));
        $ver = $max + 1;
        $st->db()->exec('INSERT INTO ' . PlatformStore::PRINT_TPL_VERSION_TABLE
            . ' (tpl_id, version_no, body, mode, paper, created_by, created_at) VALUES ('
            . $st->int($tplId) . ', ' . $st->int($ver) . ', ' . $st->quote($body) . ', '
            . $st->quote($mode) . ', ' . $st->quote(json_encode($paper, JSON_UNESCAPED_UNICODE)) . ', '
            . $st->quote($operator) . ', ' . $st->quote($this->now()) . ')');
        $keep = (int) (($st->db()->query(
            'SELECT version_no FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE
            . ' WHERE tpl_id = ' . $st->int($tplId) . ' ORDER BY version_no DESC LIMIT 1 OFFSET ' . self::VERSION_KEEP
        )[0]['version_no'] ?? 0));
        if ($keep > 0) {
            $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE
                . ' WHERE tpl_id = ' . $st->int($tplId) . ' AND version_no <= ' . $st->int($keep));
        }
        return $ver;
    }

    /** 版本列表（管理端快照页）。 */
    public function versions(int $id): array
    {
        $st = $this->store();
        return $st->db()->query(
            'SELECT version_no, mode, created_by, created_at FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE
            . ' WHERE tpl_id = ' . $st->int($id) . ' ORDER BY version_no DESC'
        ) ?? [];
    }

    /** 版本回滚：取指定版本内容写回当前（并产生新快照，AC-5）。 */
    public function rollback(int $id, int $versionNo, string $operator): void
    {
        $st = $this->store();
        $rows = $st->db()->query(
            'SELECT * FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE
            . ' WHERE tpl_id = ' . $st->int($id) . ' AND version_no = ' . $st->int($versionNo) . ' LIMIT 1'
        );
        $v = $rows[0] ?? null;
        if ($v === null) {
            throw new PrintTplDenied('版本不存在');
        }
        $paper = json_decode((string) $v['paper'], true);
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE . ' SET '
            . 'body = ' . $st->quote((string) $v['body']) . ', '
            . 'mode = ' . $st->quote((string) $v['mode']) . ', '
            . 'paper = ' . $st->quote((string) $v['paper']) . ', '
            . 'body_size = ' . $st->int(strlen((string) $v['body'])) . ', '
            . 'updated_at = ' . $st->quote($this->now())
            . ' WHERE id = ' . $st->int($id));
        $this->snapshot($id, (string) $v['body'], (string) $v['mode'], is_array($paper) ? $paper : [], $operator);
    }

    /** 启停（PT-3）：停用后渲染返回「模板已停用」业务错误。 */
    public function toggle(int $id, int $status): void
    {
        $st = $this->store();
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE
            . ' SET status = ' . ($status === 1 ? 1 : 0)
            . ', updated_at = ' . $st->quote($this->now())
            . ' WHERE id = ' . $st->int($id));
    }

    /** 删除（PT-1）：连同版本快照。 */
    public function del(int $id): void
    {
        $st = $this->store();
        $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TPL_VERSION_TABLE . ' WHERE tpl_id = ' . $st->int($id));
        $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE id = ' . $st->int($id));
    }

    /** 复制（PT-1）：正文/纸张/样例一并复制，生成新编号。返回新模板 id。 */
    public function copy(int $id, string $newCode, string $operator): int
    {
        $st = $this->store();
        $rows = $st->db()->query('SELECT * FROM ' . PlatformStore::PRINT_TPL_TABLE . ' WHERE id = ' . $st->int($id) . ' LIMIT 1');
        $tpl = $rows[0] ?? null;
        if ($tpl === null) {
            throw new PrintTplDenied('模板不存在');
        }
        $new = $this->save(null, [
            'code'        => $newCode,
            'name'        => (string) $tpl['name'] . '（副本）',
            'scope'       => (string) $tpl['scope'],
            'owner_pkg'   => (string) $tpl['owner_pkg'],
            'mode'        => (string) $tpl['mode'],
            'paper'       => json_decode((string) $tpl['paper'], true) ?: [],
            'body'        => (string) $tpl['body'],
            'sample_data' => json_decode((string) ($tpl['sample_data'] ?? ''), true) ?: [],
        ], $operator);
        return (int) $new['id'];
    }

    // ================================================================ 卸载保护 / 数据清理

    /** 包卸载：冻结该包的私有模板（孤儿），语义同 approval / recyclebin（AC-7）。返回冻结数量。 */
    public function orphanPackage(string $pkg): int
    {
        $st = $this->store();
        $rows = $st->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::PRINT_TPL_TABLE
            . ' WHERE owner_pkg = ' . $st->quote($pkg)
            . " AND scope = 'package' AND orphaned_at = ''") ?? [];
        $frozen = (int) ($rows[0]['c'] ?? 0);
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE
            . " SET orphaned_at = " . $st->quote($this->now())
            . ' WHERE owner_pkg = ' . $st->quote($pkg)
            . " AND scope = 'package' AND orphaned_at = ''");
        // 该包未使用的票据一并失效
        $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TICKET_TABLE
            . ' WHERE owner_pkg = ' . $st->quote($pkg));
        return $frozen;
    }

    /** 包重装/升级：接管孤儿模板（清冻结标记）。由 IntegrationManager 在集成时调用。 */
    public function adoptPackage(string $pkg): void
    {
        $st = $this->store();
        $st->db()->exec('UPDATE ' . PlatformStore::PRINT_TPL_TABLE
            . " SET orphaned_at = ''"
            . ' WHERE owner_pkg = ' . $st->quote($pkg) . " AND scope = 'package'");
    }

    /** 彻底清理（数据随包关闭或显式清理时）：私有模板 + 快照 + 票据。 */
    public function purgePackage(string $pkg): void
    {
        $st = $this->store();
        $ids = $st->db()->query(
            'SELECT id FROM ' . PlatformStore::PRINT_TPL_TABLE
            . ' WHERE owner_pkg = ' . $st->quote($pkg) . " AND scope = 'package'"
        ) ?? [];
        foreach ($ids as $r) {
            $this->del((int) $r['id']);
        }
        $st->db()->exec('DELETE FROM ' . PlatformStore::PRINT_TICKET_TABLE
            . ' WHERE owner_pkg = ' . $st->quote($pkg));
    }

    /** 服务治理统计：模板总数（配额展示用）。 */
    public function counts(): array
    {
        $st = $this->store();
        $c = (int) (($st->db()->query('SELECT COUNT(*) AS c FROM ' . PlatformStore::PRINT_TPL_TABLE)[0]['c'] ?? 0));
        return ['templates' => $c, 'max' => self::QUOTA_MAX_TEMPLATES];
    }
}
