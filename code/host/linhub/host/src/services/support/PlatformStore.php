<?php

declare(strict_types=1);

namespace linhub\host\services\support;

use linhub\host\migration\DbGateway;
use linhub\host\migration\PdoGateway;
use think\facade\Env;

/**
 * 平台公共服务共享数据层（附件 / 数据字典 / 站内消息）。
 *
 * 设计约定（与 LinserviceStore 一致）：
 *  - 裸 PDO + 手写方言 SQL，不依赖 ORM；表统一以 lg_mgt_ 为前缀，落在系统库 lin_hub；
 *  - 幂等建表 CREATE TABLE IF NOT EXISTS，MySQL 强制 InnoDB；
 *  - 字符串值手工 quote 转义，时间存 date('c') 字符串；
 *  - DB 不可用时构造/ensure 抛异常，由调用方决定降级策略（管理页降级不白屏，服务接口向上抛）。
 *
 * 各业务服务把本类作为唯一的 SQL 出口，避免在四个服务里重复建表方言与连接代码。
 */
final class PlatformStore
{
    public const ATTACHMENT_TABLE = 'lg_mgt_attachment';
    public const ATTACH_CLEAN_TABLE = 'lg_mgt_attachment_clean';
    public const DICT_TABLE = 'lg_mgt_dict_item';
    public const MESSAGE_TABLE = 'lg_mgt_message';
    public const RECIPIENT_TABLE = 'lg_mgt_message_recipient';
    public const LLM_MODEL_TABLE = 'lg_mgt_llm_model';
    public const LLM_CALL_LOG_TABLE = 'lg_mgt_llm_call_log';
    public const LLM_CALLER_QUOTA_TABLE = 'lg_mgt_llm_caller_quota';
    public const TRANSLATION_TABLE = 'lg_mgt_translation';
    public const RECYCLE_BIN_TABLE = 'lg_mgt_recycle_bin';
    public const APPR_FLOW_TABLE = 'lg_mgt_appr_flow';
    public const APPR_INSTANCE_TABLE = 'lg_mgt_appr_instance';
    public const APPR_NODE_TABLE = 'lg_mgt_appr_node_instance';
    public const APPR_ACTION_TABLE = 'lg_mgt_appr_action';
    public const PRINT_TPL_TABLE = 'lg_mgt_print_tpl';
    public const PRINT_TPL_VERSION_TABLE = 'lg_mgt_print_tpl_version';
    public const PRINT_TICKET_TABLE = 'lg_mgt_print_ticket';
    public const TASK_DEF_TABLE = 'lg_mgt_task_def';
    public const TASK_RUN_TABLE = 'lg_mgt_task_run';
    public const TASK_IDEM_TABLE = 'lg_mgt_task_idem';
    public const TASK_HEARTBEAT_TABLE = 'lg_mgt_task_heartbeat';
    public const TASK_KV_TABLE = 'lg_mgt_task_kv';

    private DbGateway $db;

    public function __construct(?DbGateway $db = null)
    {
        $this->db = $db ?? self::connect();
        $this->ensure();
    }

    /** 按 env（DB_TYPE/DB_HOST/...）建立系统库网关。 */
    public static function connect(): DbGateway
    {
        $driver  = (string) Env::get('DB_TYPE', 'mysql');
        $host    = (string) Env::get('DB_HOST', '127.0.0.1');
        $port    = (string) Env::get('DB_PORT', '3306');
        $db      = (string) Env::get('DB_NAME', '');
        $user    = (string) Env::get('DB_USER', 'root');
        $pass    = (string) Env::get('DB_PASS', '');
        $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');
        $dsn     = "{$driver}:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo     = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        return new PdoGateway($pdo);
    }

    public function db(): DbGateway
    {
        return $this->db;
    }

    /** 幂等建立全部平台服务表。 */
    public function ensure(): void
    {
        $this->ensureAttachmentTables();
        $this->ensureDictTables();
        $this->ensureMessageTables();
        $this->ensureLlmTables();
        $this->ensureTranslationTables();
        $this->ensureRecycleBinTables();
        $this->ensureApprovalTables();
        $this->ensurePrintTplTables();
        $this->ensureSchedulerTables();
    }

    /** 附件元数据 + 清理记录表。 */
    public function ensureAttachmentTables(): void
    {
        if (!in_array(self::ATTACHMENT_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::ATTACHMENT_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'token VARCHAR(64) NOT NULL, '
                . 'owner_pkg VARCHAR(191) NOT NULL, '
                . 'uploader_id VARCHAR(191) NOT NULL, '
                . 'uploader_name VARCHAR(191) DEFAULT \'\', '
                . 'origin_name VARCHAR(255) NOT NULL, '
                . 'storage_path VARCHAR(500) NOT NULL, '
                . 'driver VARCHAR(32) NOT NULL, '
                . 'mime VARCHAR(128) DEFAULT \'\', '
                . 'ext VARCHAR(32) DEFAULT \'\', '
                . 'size INT NOT NULL DEFAULT 0, '
                . 'is_public INT NOT NULL DEFAULT 0, '
                . 'is_image INT NOT NULL DEFAULT 0, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'active\', '
                . 'expire_at VARCHAR(40) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
        }
        if (!in_array(self::ATTACH_CLEAN_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::ATTACH_CLEAN_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'trigger_type VARCHAR(32) NOT NULL, '
                . 'driver VARCHAR(64) NOT NULL, '
                . 'scanned INT NOT NULL DEFAULT 0, '
                . 'deleted INT NOT NULL DEFAULT 0, '
                . 'detail TEXT DEFAULT \'\', '
                . 'operator_id VARCHAR(191) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
        }
    }

    /** 数据字典条目表（key 即标识，namespace 做功能包隔离）。 */
    public function ensureDictTables(): void
    {
        if (!in_array(self::DICT_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::DICT_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'namespace VARCHAR(191) NOT NULL, '
                . 'dict_key VARCHAR(191) NOT NULL, '
                . 'value TEXT DEFAULT \'\', '
                . 'value_type VARCHAR(32) NOT NULL DEFAULT \'string\', '
                . 'owner_pkg VARCHAR(191) NOT NULL, '
                . 'is_public INT NOT NULL DEFAULT 0, '
                . 'remark VARCHAR(255) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
        }
    }

    /** 站内消息主体表 + 收件人快照表。 */
    public function ensureMessageTables(): void
    {
        if (!in_array(self::MESSAGE_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::MESSAGE_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'msg_key VARCHAR(64) NOT NULL, '
                . 'title VARCHAR(255) NOT NULL, '
                . 'content TEXT DEFAULT \'\', '
                . 'link VARCHAR(500) DEFAULT \'\', '
                . 'source_pkg VARCHAR(191) DEFAULT \'\', '
                . 'sender_id VARCHAR(191) DEFAULT \'\', '
                . 'sender_name VARCHAR(191) DEFAULT \'\', '
                . 'target_type VARCHAR(32) NOT NULL DEFAULT \'user\', '
                . 'target_ref VARCHAR(191) DEFAULT \'\', '
                . 'recipient_count INT NOT NULL DEFAULT 0, '
                . 'created_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
        }
        if (!in_array(self::RECIPIENT_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::RECIPIENT_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'msg_key VARCHAR(64) NOT NULL, '
                . 'user_id VARCHAR(191) NOT NULL, '
                . 'user_name VARCHAR(191) DEFAULT \'\', '
                . 'resolved_from VARCHAR(64) DEFAULT \'user\', '
                . 'is_read INT NOT NULL DEFAULT 0, '
                . 'read_at VARCHAR(40) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
        }
    }

    /** SQL 字符串字面量转义（与 LinserviceStore 同一约定）。 */
    public function quote(string $v): string
    {
        // MySQL 默认模式下反斜杠是转义字符，须先翻倍，否则 \uXXXX、\n 等 JSON 转义序列入库时会被吞掉反斜杠。
        if ($this->db->dialect() === 'mysql') {
            $v = str_replace('\\', '\\\\', $v);
        }
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** 整型归一。 */
    public function int(mixed $v): int
    {
        return (int) $v;
    }

    /**
     * LLM 网关三张表：模型实例、调用审计、调用方配额。
     *
     * 时间统一用 VARCHAR(40) date('c') 字符串（与其他平台表一致）。
     * MySQL 加索引；SQLite/pgsql 跳过索引以保持幂等简化。
     */
    public function ensureLlmTables(): void
    {
        if (!in_array(self::LLM_MODEL_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::LLM_MODEL_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'code VARCHAR(64) NOT NULL, '
                . 'provider VARCHAR(64) NOT NULL, '
                . 'model_name VARCHAR(128) NOT NULL, '
                . 'capability VARCHAR(16) NOT NULL DEFAULT \'chat\', '
                . 'max_tokens INT NOT NULL DEFAULT 4096, '
                . 'temperature_default DECIMAL(4,2) NOT NULL DEFAULT 0.70, '
                . 'is_enabled INT NOT NULL DEFAULT 1, '
                . 'daily_token_quota BIGINT NOT NULL DEFAULT 0, '
                . 'unit_cost_input DECIMAL(12,6) DEFAULT NULL, '
                . 'unit_cost_output DECIMAL(12,6) DEFAULT NULL, '
                . 'remark VARCHAR(255) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::LLM_MODEL_TABLE . ' ADD UNIQUE KEY uk_code (code)');
            }
        }

        if (!in_array(self::LLM_CALL_LOG_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::LLM_CALL_LOG_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'call_id VARCHAR(36) NOT NULL, '
                . 'caller_effective VARCHAR(64) NOT NULL, '
                . 'caller_declared VARCHAR(64) DEFAULT NULL, '
                . 'model_code VARCHAR(64) NOT NULL, '
                . 'provider VARCHAR(64) NOT NULL, '
                . 'type VARCHAR(16) NOT NULL, '
                . 'status VARCHAR(16) NOT NULL, '
                . 'prompt_text MEDIUMTEXT, '
                . 'completion_text MEDIUMTEXT, '
                . 'prompt_tokens INT DEFAULT NULL, '
                . 'completion_tokens INT DEFAULT NULL, '
                . 'usage_estimated INT NOT NULL DEFAULT 0, '
                . 'duration_ms INT DEFAULT NULL, '
                . 'cost_input DECIMAL(12,6) DEFAULT NULL, '
                . 'cost_output DECIMAL(12,6) DEFAULT NULL, '
                . 'error_summary VARCHAR(512) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::LLM_CALL_LOG_TABLE . ' ADD UNIQUE KEY uk_call_id (call_id)');
                $this->db->exec('ALTER TABLE ' . self::LLM_CALL_LOG_TABLE . ' ADD INDEX idx_caller_time (caller_effective, created_at)');
                $this->db->exec('ALTER TABLE ' . self::LLM_CALL_LOG_TABLE . ' ADD INDEX idx_model_time (model_code, created_at)');
            }
        }

        if (!in_array(self::LLM_CALLER_QUOTA_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::LLM_CALLER_QUOTA_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'caller VARCHAR(64) NOT NULL, '
                . 'model_code VARCHAR(64) NOT NULL, '
                . 'daily_token_limit BIGINT NOT NULL DEFAULT 0, '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::LLM_CALLER_QUOTA_TABLE . ' ADD UNIQUE KEY uk_caller_model (caller, model_code)');
            }
        }
    }

    /**
     * 翻译条目表：一行 = 一个 (namespace, key, lang) 的译文。
     *
     * 唯一索引保证同一 key 同语言只有一条；namespace 做功能包隔离，
     * host 为保留命名空间（平台自身文案）。
     */
    public function ensureTranslationTables(): void
    {
        if (!in_array(self::TRANSLATION_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::TRANSLATION_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'namespace VARCHAR(64) NOT NULL, '
                . 'dict_key VARCHAR(191) NOT NULL, '
                . 'lang VARCHAR(16) NOT NULL, '
                . 'value TEXT DEFAULT \'\', '
                . 'is_public INT NOT NULL DEFAULT 0, '
                . 'owner_pkg VARCHAR(64) NOT NULL, '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::TRANSLATION_TABLE . ' ADD UNIQUE KEY uk_ns_key_lang (namespace, dict_key, lang)');
                $this->db->exec('ALTER TABLE ' . self::TRANSLATION_TABLE . ' ADD INDEX idx_ns (namespace)');
            }
        }
    }

    /**
     * 回收站托管表：一行 = 一个被删除数据的 JSON 快照条目。
     *
     * payload 存任意结构 JSON（结构解释权归功能包），namespace 做功能包隔离，
     * host 为保留命名空间。expires_at 空串 = 永久保留；orphaned_at 非空 = 所属包已卸载。
     */
    public function ensureRecycleBinTables(): void
    {
        if (!in_array(self::RECYCLE_BIN_TABLE, $this->db->tables(), true)) {
            $payloadType = $this->db->dialect() === 'mysql' ? 'LONGTEXT' : 'TEXT';
            $this->db->exec(
                'CREATE TABLE ' . self::RECYCLE_BIN_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'namespace VARCHAR(64) NOT NULL, '
                . 'token VARCHAR(64) NOT NULL, '
                . 'source_table VARCHAR(191) NOT NULL, '
                . 'source_pk VARCHAR(191) NOT NULL, '
                . 'item_key VARCHAR(191) DEFAULT \'\', '
                . 'title VARCHAR(255) DEFAULT \'\', '
                . 'payload ' . $payloadType . ' NOT NULL, '
                . 'payload_size INT NOT NULL DEFAULT 0, '
                . 'meta TEXT DEFAULT \'\', '
                . 'orphaned_at VARCHAR(40) DEFAULT \'\', '
                . 'deleted_at VARCHAR(40) DEFAULT \'\', '
                . 'expires_at VARCHAR(40) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::RECYCLE_BIN_TABLE . ' ADD UNIQUE KEY uk_token (token)');
                $this->db->exec('ALTER TABLE ' . self::RECYCLE_BIN_TABLE . ' ADD INDEX idx_namespace (namespace)');
                $this->db->exec('ALTER TABLE ' . self::RECYCLE_BIN_TABLE . ' ADD INDEX idx_expires (expires_at)');
            }
        }
    }

    /** 流程定义表是否已具备 source 列（存量库迁移探测）。 */
    private function flowTableHasSource(): bool
    {
        if ($this->db->dialect() === 'mysql') {
            $cols = $this->db->query("SHOW COLUMNS FROM " . self::APPR_FLOW_TABLE . " LIKE 'source'");
            return is_array($cols) && $cols !== [];
        }
        $cols = $this->db->query('PRAGMA table_info(' . self::APPR_FLOW_TABLE . ')');
        foreach ((array) $cols as $col) {
            if (is_array($col) && (string) ($col['name'] ?? '') === 'source') {
                return true;
            }
        }
        return false;
    }

    /**
     * 审批服务四张表：流程定义、审批单、节点实例、动作流水。
     *
     * definition/payload/approver_snapshot 等 JSON 列结构解释权归 ApprovalService；
     * instance.orphaned 非零 = 所属功能包已卸载（冻结推进，等待接管）。
     * node_instance.status 含 error（解析器失败时节点保持待处理单据 pending）。
     */
    public function ensureApprovalTables(): void
    {
        $payloadType = $this->db->dialect() === 'mysql' ? 'LONGTEXT' : 'TEXT';

        if (!in_array(self::APPR_FLOW_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::APPR_FLOW_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'package VARCHAR(64) NOT NULL, '
                . 'flow_key VARCHAR(191) NOT NULL, '
                . 'name VARCHAR(255) DEFAULT \'\', '
                . 'definition ' . $payloadType . ' NOT NULL, '
                . 'version INT NOT NULL DEFAULT 1, '
                . 'status VARCHAR(16) NOT NULL DEFAULT \'active\', '
                . 'source VARCHAR(16) NOT NULL DEFAULT \'package\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::APPR_FLOW_TABLE . ' ADD UNIQUE KEY uk_pkg_flow (package, flow_key)');
            }
        } elseif (!$this->flowTableHasSource()) {
            // 存量库幂等补列：source=package 系统定义（功能包注册）/ admin 后台自定义
            $this->db->exec(
                'ALTER TABLE ' . self::APPR_FLOW_TABLE
                . " ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'package'"
            );
        }

        if (!in_array(self::APPR_INSTANCE_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::APPR_INSTANCE_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'approval_no VARCHAR(64) NOT NULL, '
                . 'package VARCHAR(64) NOT NULL, '
                . 'flow_key VARCHAR(191) NOT NULL, '
                . 'flow_version INT NOT NULL DEFAULT 1, '
                . 'title VARCHAR(255) DEFAULT \'\', '
                . 'summary VARCHAR(500) DEFAULT \'\', '
                . 'payload ' . $payloadType . ' NOT NULL, '
                . 'payload_size INT NOT NULL DEFAULT 0, '
                . 'submitter_id VARCHAR(191) NOT NULL, '
                . 'submitter_name VARCHAR(191) DEFAULT \'\', '
                . 'status VARCHAR(16) NOT NULL DEFAULT \'pending\', '
                . 'orphaned INT NOT NULL DEFAULT 0, '
                . 'submitted_at VARCHAR(40) DEFAULT \'\', '
                . 'finished_at VARCHAR(40) DEFAULT \'\', '
                . 'finish_comment TEXT DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::APPR_INSTANCE_TABLE . ' ADD UNIQUE KEY uk_approval_no (approval_no)');
                $this->db->exec('ALTER TABLE ' . self::APPR_INSTANCE_TABLE . ' ADD INDEX idx_pkg_status (package, status)');
                $this->db->exec('ALTER TABLE ' . self::APPR_INSTANCE_TABLE . ' ADD INDEX idx_submitter (submitter_id, status)');
            }
        }

        if (!in_array(self::APPR_NODE_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::APPR_NODE_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'instance_id INT NOT NULL, '
                . 'node_id VARCHAR(191) NOT NULL, '
                . 'node_type VARCHAR(16) NOT NULL DEFAULT \'approval\', '
                . 'mode VARCHAR(16) NOT NULL DEFAULT \'any\', '
                . 'approver_snapshot ' . $payloadType . ' DEFAULT NULL, '
                . 'approver_source ' . $payloadType . ' DEFAULT NULL, '
                . 'status VARCHAR(16) NOT NULL DEFAULT \'waiting\', '
                . 'branch_path ' . $payloadType . ' DEFAULT NULL, '
                . 'join_ctx ' . $payloadType . ' DEFAULT NULL, '
                . 'activated_at VARCHAR(40) DEFAULT \'\', '
                . 'finished_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::APPR_NODE_TABLE . ' ADD INDEX idx_instance (instance_id)');
                $this->db->exec('ALTER TABLE ' . self::APPR_NODE_TABLE . ' ADD INDEX idx_status (status)');
            }
        }

        if (!in_array(self::APPR_ACTION_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::APPR_ACTION_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'instance_id INT NOT NULL, '
                . 'node_instance_id INT NOT NULL DEFAULT 0, '
                . 'actor_id VARCHAR(191) NOT NULL, '
                . 'actor_name VARCHAR(191) DEFAULT \'\', '
                . 'action VARCHAR(16) NOT NULL, '
                . 'comment TEXT DEFAULT \'\', '
                . 'acted_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::APPR_ACTION_TABLE . ' ADD INDEX idx_instance_time (instance_id, acted_at)');
            }
        }
    }

    /**
     * 单据打印模板三张表：模板、版本快照、打印票据（printtpl 服务）。
     *
     * tpl.code 全局唯一（模板编号 PT-11）；scope=platform 平台公共 / package 包级私有（T-1）；
     * orphaned_at 非空 = 归属包已卸载（冻结渲染，等待重装接管，语义同 approval/recyclebin）。
     * ticket 为 printUrl 一次性数据票据（T-7），5 分钟有效、单次使用。
     */
    public function ensurePrintTplTables(): void
    {
        $payloadType = $this->db->dialect() === 'mysql' ? 'LONGTEXT' : 'TEXT';

        if (!in_array(self::PRINT_TPL_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::PRINT_TPL_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'code VARCHAR(191) NOT NULL, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'scope VARCHAR(16) NOT NULL DEFAULT \'platform\', '
                . 'owner_pkg VARCHAR(64) NOT NULL DEFAULT \'\', '
                . 'status INT NOT NULL DEFAULT 1, '
                . 'mode VARCHAR(16) NOT NULL DEFAULT \'visual\', '
                . 'paper ' . $payloadType . ' NOT NULL, '
                . 'body ' . $payloadType . ' NOT NULL, '
                . 'body_size INT NOT NULL DEFAULT 0, '
                . 'sample_data ' . $payloadType . ' DEFAULT NULL, '
                . 'last_test_at VARCHAR(40) DEFAULT \'\', '
                . 'orphaned_at VARCHAR(40) DEFAULT \'\', '
                . 'created_by VARCHAR(191) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::PRINT_TPL_TABLE . ' ADD UNIQUE KEY uk_code (code)');
                $this->db->exec('ALTER TABLE ' . self::PRINT_TPL_TABLE . ' ADD INDEX idx_owner (owner_pkg, scope)');
            }
        }

        if (!in_array(self::PRINT_TPL_VERSION_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::PRINT_TPL_VERSION_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'tpl_id INT NOT NULL, '
                . 'version_no INT NOT NULL, '
                . 'body ' . $payloadType . ' NOT NULL, '
                . 'mode VARCHAR(16) NOT NULL DEFAULT \'visual\', '
                . 'paper ' . $payloadType . ' NOT NULL, '
                . 'created_by VARCHAR(191) DEFAULT \'\', '
                . 'created_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::PRINT_TPL_VERSION_TABLE . ' ADD UNIQUE KEY uk_tpl_ver (tpl_id, version_no)');
            }
        }

        if (!in_array(self::PRINT_TICKET_TABLE, $this->db->tables(), true)) {
            $this->db->exec(
                'CREATE TABLE ' . self::PRINT_TICKET_TABLE . ' ('
                . $this->primaryKeySql() . ', '
                . 'token VARCHAR(64) NOT NULL, '
                . 'owner_pkg VARCHAR(64) NOT NULL DEFAULT \'\', '
                . 'tpl_code VARCHAR(191) NOT NULL, '
                . 'data ' . $payloadType . ' NOT NULL, '
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'expires_at VARCHAR(40) DEFAULT \'\', '
                . 'used_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::PRINT_TICKET_TABLE . ' ADD UNIQUE KEY uk_token (token)');
                $this->db->exec('ALTER TABLE ' . self::PRINT_TICKET_TABLE . ' ADD INDEX idx_expires (expires_at)');
            }
        }
    }

    /**
     * 异步任务托管的五张表：周期定义、执行实例、提交幂等、心跳存活、运行态小状态。
     *
     * 时间列一律 VARCHAR(40) 存 ISO 8601 带偏移串，字符串比较即可当时间比较用。
     */
    public function ensureSchedulerTables(): void
    {
        $long = $this->db->dialect() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $this->createSchedulerTable(
            self::TASK_DEF_TABLE,
            'namespace VARCHAR(64) NOT NULL, '
            . 'def_key VARCHAR(128) NOT NULL, '
            . 'handler VARCHAR(191) NOT NULL, '
            . 'mode VARCHAR(16) NOT NULL, '
            . 'expr VARCHAR(191) NOT NULL, '
            . 'timezone VARCHAR(64) DEFAULT \'\', '
            . 'payload ' . $long . ' NOT NULL, '
            . 'status VARCHAR(16) NOT NULL DEFAULT \'active\', '
            . 'enabled INT NOT NULL DEFAULT 1, '
            . 'timeout_sec INT NOT NULL DEFAULT 0, '
            . 'next_run VARCHAR(40) DEFAULT \'\', '
            . 'last_run_at VARCHAR(40) DEFAULT \'\', '
            . 'last_status VARCHAR(16) DEFAULT \'\', '
            . 'last_error VARCHAR(500) DEFAULT \'\', '
            . 'fail_streak INT NOT NULL DEFAULT 0, '
            . 'orphaned_at VARCHAR(40) DEFAULT \'\', '
            . 'created_by VARCHAR(64) DEFAULT \'\', '
            . 'created_at VARCHAR(40) DEFAULT \'\', '
            . 'updated_at VARCHAR(40) DEFAULT \'\'',
            ['uk_ns_key' => '(namespace, def_key)'],
            ['idx_due' => '(enabled, status, next_run)', 'idx_ns' => '(namespace)']
        );
        $this->createSchedulerTable(
            self::TASK_RUN_TABLE,
            'run_id CHAR(32) NOT NULL, '
            . 'def_id INT DEFAULT NULL, '
            . 'namespace VARCHAR(64) NOT NULL, '
            . 'task_key VARCHAR(128) DEFAULT \'\', '
            . 'handler VARCHAR(191) NOT NULL, '
            . 'state VARCHAR(16) NOT NULL DEFAULT \'queued\', '
            . 'priority INT NOT NULL DEFAULT 0, '
            . 'payload ' . $long . ' NOT NULL, '
            . 'result ' . $long . ' NOT NULL, '
            . 'error TEXT, '
            . 'progress INT NOT NULL DEFAULT 0, '
            . 'message VARCHAR(500) DEFAULT \'\', '
            . 'plan_time VARCHAR(40) DEFAULT \'\', '
            . 'started_at VARCHAR(40) DEFAULT \'\', '
            . 'finished_at VARCHAR(40) DEFAULT \'\', '
            . 'heartbeat_at VARCHAR(40) DEFAULT \'\', '
            . 'lease_sec INT NOT NULL DEFAULT 0, '
            . 'attempt INT NOT NULL DEFAULT 1, '
            . 'retry_of CHAR(32) DEFAULT \'\', '
            . 'cancel_requested INT NOT NULL DEFAULT 0, '
            . 'idem_key VARCHAR(191) DEFAULT \'\', '
            . 'created_by VARCHAR(64) DEFAULT \'\', '
            . 'created_at VARCHAR(40) DEFAULT \'\', '
            . 'updated_at VARCHAR(40) DEFAULT \'\', '
            . 'orphaned_at VARCHAR(40) DEFAULT \'\'',
            ['uk_run' => '(run_id)', 'uk_def_plan' => '(def_id, plan_time)'],
            [
                'idx_claim'    => '(state, priority, plan_time)',
                'idx_ns_state' => '(namespace, state)',
                'idx_lease'    => '(state, heartbeat_at)',
                'idx_created'  => '(namespace, created_at)',
            ]
        );
        $this->createSchedulerTable(
            self::TASK_IDEM_TABLE,
            'namespace VARCHAR(64) NOT NULL, '
            . 'idem_key VARCHAR(191) NOT NULL, '
            . 'run_id CHAR(32) NOT NULL, '
            . 'created_at VARCHAR(40) DEFAULT \'\', '
            . 'expires_at VARCHAR(40) DEFAULT \'\'',
            ['uk_ns_idem' => '(namespace, idem_key)'],
            ['idx_expires' => '(expires_at)']
        );
        $this->createSchedulerTable(
            self::TASK_HEARTBEAT_TABLE,
            'worker VARCHAR(64) NOT NULL, '
            . 'pid INT NOT NULL DEFAULT 0, '
            . 'os VARCHAR(16) DEFAULT \'\', '
            . 'sapi VARCHAR(32) DEFAULT \'\', '
            . 'mode VARCHAR(16) DEFAULT \'\', '
            . 'code_version VARCHAR(32) DEFAULT \'\', '
            . 'started_at VARCHAR(40) DEFAULT \'\', '
            . 'heartbeat_at VARCHAR(40) DEFAULT \'\', '
            . 'last_tick_ms INT NOT NULL DEFAULT 0, '
            . 'last_message VARCHAR(500) DEFAULT \'\'',
            ['uk_worker' => '(worker)'],
            []
        );
        $this->createSchedulerTable(
            self::TASK_KV_TABLE,
            'k VARCHAR(191) NOT NULL, '
            . 'v ' . $long . ' NOT NULL, '
            . 'updated_at VARCHAR(40) DEFAULT \'\'',
            ['uk_k' => '(k)'],
            []
        );
    }

    /** 建单表 + MySQL 索引（其余方言只保留主键与唯一约束）。 */
    private function createSchedulerTable(string $table, string $columns, array $uniques, array $indexes): void
    {
        if (in_array($table, $this->db->tables(), true)) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE ' . $table . ' (' . $this->primaryKeySql() . ', ' . $columns . ')' . $this->tableOptions()
        );
        if ($this->db->dialect() !== 'mysql') {
            return;
        }
        foreach ($uniques as $name => $columns) {
            $this->db->exec('ALTER TABLE ' . $table . ' ADD UNIQUE KEY ' . $name . ' ' . $columns);
        }
        foreach ($indexes as $name => $columns) {
            $this->db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $name . ' ' . $columns);
        }
    }

    // —— 执行实例（task_run） ——

    /** 插入一条实例；重复（唯一键冲突）向上抛，由调用方判重。 */
    public function insertRun(array $row): void
    {
        $this->db->exec($this->insertRunSql($row, false));
    }

    /** 插入一条实例；重复视为「已生成」返回 false，不抛异常。 */
    public function insertRunIgnore(array $row): bool
    {
        try {
            return $this->db->execAffected($this->insertRunSql($row, true)) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function insertRunSql(array $row, bool $ignore): string
    {
        $runId = $this->quote((string) ($row['run_id'] ?? ''));
        $defId = ($row['def_id'] ?? null) === null ? 'NULL' : (string) $this->int($row['def_id']);
        $now = $this->quote((string) ($row['created_at'] ?? date('c')));
        return 'INSERT ' . ($ignore && $this->db->dialect() === 'mysql' ? 'IGNORE ' : '')
            . 'INTO ' . self::TASK_RUN_TABLE . ' '
            . '(run_id, def_id, namespace, task_key, handler, state, priority, payload, result, error, '
            . 'plan_time, attempt, retry_of, idem_key, created_by, created_at, updated_at) VALUES ('
            . $runId . ', ' . $defId . ', '
            . $this->quote((string) ($row['namespace'] ?? '')) . ', '
            . $this->quote((string) ($row['task_key'] ?? '')) . ', '
            . $this->quote((string) ($row['handler'] ?? '')) . ', '
            . $this->quote((string) ($row['state'] ?? 'queued')) . ', '
            . $this->int($row['priority'] ?? 0) . ', '
            . $this->quote((string) ($row['payload'] ?? '')) . ', '
            . '\'\', NULL, '
            . $this->quote((string) ($row['plan_time'] ?? '')) . ', '
            . $this->int($row['attempt'] ?? 1) . ', '
            . $this->quote((string) ($row['retry_of'] ?? '')) . ', '
            . $this->quote((string) ($row['idem_key'] ?? '')) . ', '
            . $this->quote((string) ($row['created_by'] ?? '')) . ', '
            . $now . ', ' . $now . ')';
    }

    /** 原子认领：仅 queued 可进入 running，返回是否抢到。 */
    public function claimRun(int $id, int $leaseSec): bool
    {
        $now = date('c');
        $sql = 'UPDATE ' . self::TASK_RUN_TABLE . ' SET '
            . 'state=' . $this->quote('running') . ', '
            . 'started_at=' . $this->quote($now) . ', '
            . 'heartbeat_at=' . $this->quote($now) . ', '
            . 'lease_sec=' . $this->int($leaseSec) . ', '
            . 'attempt=attempt+1, '
            . 'updated_at=' . $this->quote($now) . ' '
            . 'WHERE id=' . $this->int($id) . ' AND state=' . $this->quote('queued');
        return $this->db->execAffected($sql) === 1;
    }

    /** queued 实例直接取消；返回是否生效。 */
    public function cancelQueuedRun(string $runId, string $namespace): bool
    {
        $now = date('c');
        $sql = 'UPDATE ' . self::TASK_RUN_TABLE . ' SET '
            . 'state=' . $this->quote('cancelled') . ', '
            . 'finished_at=' . $this->quote($now) . ', '
            . 'updated_at=' . $this->quote($now) . ' '
            . 'WHERE run_id=' . $this->quote($runId)
            . ' AND namespace=' . $this->quote($namespace)
            . ' AND state=' . $this->quote('queued');
        return $this->db->execAffected($sql) === 1;
    }

    /** running 实例打取消标记，由 handler 轮询 heartbeat 自行退出。 */
    public function requestRunCancel(string $runId, string $namespace): bool
    {
        $now = date('c');
        $sql = 'UPDATE ' . self::TASK_RUN_TABLE . ' SET '
            . 'cancel_requested=1, updated_at=' . $this->quote($now) . ' '
            . 'WHERE run_id=' . $this->quote($runId)
            . ' AND namespace=' . $this->quote($namespace)
            . ' AND state=' . $this->quote('running');
        return $this->db->execAffected($sql) === 1;
    }

    /** 上报进度（0~100）与消息。 */
    public function updateRunProgress(string $runId, int $percent, string $message = ''): void
    {
        $sets = ['progress=' . $this->int(max(0, min(100, $percent)))];
        if ($message !== '') {
            $sets[] = 'message=' . $this->quote(mb_substr($message, 0, 500));
        }
        $sets[] = 'updated_at=' . $this->quote(date('c'));
        $this->db->exec(
            'UPDATE ' . self::TASK_RUN_TABLE . ' SET ' . implode(', ', $sets)
            . ' WHERE run_id=' . $this->quote($runId) . ' AND state=' . $this->quote('running')
        );
    }

    /** 续期租约。 */
    public function heartbeatRun(string $runId, int $leaseSec): void
    {
        $this->db->exec(
            'UPDATE ' . self::TASK_RUN_TABLE . ' SET heartbeat_at=' . $this->quote(date('c'))
            . ', lease_sec=' . $this->int($leaseSec)
            . ' WHERE run_id=' . $this->quote($runId) . ' AND state=' . $this->quote('running')
        );
    }

    public function runCancelRequested(string $runId): bool
    {
        $rows = $this->db->query(
            'SELECT cancel_requested FROM ' . self::TASK_RUN_TABLE . ' WHERE run_id=' . $this->quote($runId) . ' LIMIT 1'
        );
        return (int) ($rows[0]['cancel_requested'] ?? 0) === 1;
    }

    /** 条件落终态：必须是 running 才更新，返回是否生效。 */
    public function finishRun(string $runId, string $state, array $fields): bool
    {
        $allowed = ['result', 'error', 'progress', 'message', 'finished_at', 'heartbeat_at', 'orphaned_at'];
        $sets = ['state=' . $this->quote($state)];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if ($key === 'progress') {
                $sets[] = $key . '=' . $this->int($value);
            } elseif ($key === 'error') {
                $sets[] = $key . '=' . ($value === null ? 'NULL' : $this->quote((string) $value));
            } else {
                $sets[] = $key . '=' . $this->quote((string) $value);
            }
        }
        $sets[] = 'updated_at=' . $this->quote(date('c'));
        $sql = 'UPDATE ' . self::TASK_RUN_TABLE . ' SET ' . implode(', ', $sets)
            . ' WHERE run_id=' . $this->quote($runId) . ' AND state=' . $this->quote('running');
        return $this->db->execAffected($sql) === 1;
    }

    /** 租约回收：running 且心跳早于阈值的行判 failed(stale)。 */
    public function reclaimStaleRuns(string $before): int
    {
        $now = date('c');
        $sql = 'UPDATE ' . self::TASK_RUN_TABLE . ' SET '
            . 'state=' . $this->quote('failed') . ', '
            . 'error=' . $this->quote('stale: 租约超时未续期') . ', '
            . 'finished_at=' . $this->quote($now) . ', '
            . 'updated_at=' . $this->quote($now) . ' '
            . 'WHERE state=' . $this->quote('running')
            . ' AND heartbeat_at<>' . $this->quote('')
            . ' AND heartbeat_at<' . $this->quote($before);
        return $this->db->execAffected($sql);
    }

    public function findRun(string $runId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TASK_RUN_TABLE . ' WHERE run_id=' . $this->quote($runId) . ' LIMIT 1'
        );
        return $rows === [] ? null : $rows[0];
    }

    public function findRunById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TASK_RUN_TABLE . ' WHERE id=' . $this->int($id) . ' LIMIT 1'
        );
        return $rows === [] ? null : $rows[0];
    }

    /** 分页检索实例；列表不带 result 大字段，只带 result_size。 */
    public function listRuns(array $where, int $page, int $size): array
    {
        $filter = $this->runWhere($where);
        $offset = max(0, ($page - 1) * $size);
        $items = $this->db->query(
            'SELECT id, run_id, def_id, namespace, task_key, handler, state, priority, progress, message, '
            . 'plan_time, started_at, finished_at, heartbeat_at, lease_sec, attempt, retry_of, cancel_requested, '
            . 'created_at, updated_at, error, LENGTH(result) AS result_size '
            . 'FROM ' . self::TASK_RUN_TABLE . $filter
            . ' ORDER BY id DESC LIMIT ' . $this->int($size) . ' OFFSET ' . $this->int($offset)
        );
        return [
            'items' => $items,
            'total' => $this->countRuns($where),
            'page'  => $page,
            'size'  => $size,
        ];
    }

    public function countRuns(array $where): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . self::TASK_RUN_TABLE . $this->runWhere($where)
        );
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** 列表筛选条件（state / handler / task_key / namespace / def_id / 日期区间）。 */
    private function runWhere(array $where): string
    {
        $clauses = [];
        foreach (['namespace', 'state', 'handler', 'task_key'] as $key) {
            $value = trim((string) ($where[$key] ?? ''));
            if ($value !== '') {
                $clauses[] = $key . '=' . $this->quote($value);
            }
        }
        if (isset($where['def_id']) && $where['def_id'] !== null && $where['def_id'] !== '') {
            $clauses[] = 'def_id=' . $this->int($where['def_id']);
        }
        foreach (['date_from', 'date_to'] as $key) {
            $value = trim((string) ($where[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $clauses[] = 'created_at ' . ($key === 'date_from' ? '>=' : '<=') . $this->quote($value);
        }
        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    /** 统计指定状态数量；namespace 为空统计全平台。 */
    public function countRunsInState(string $state, ?string $namespace = null): int
    {
        $clause = 'state=' . $this->quote($state);
        if ($namespace !== null && $namespace !== '') {
            $clause .= ' AND namespace=' . $this->quote($namespace);
        }
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . self::TASK_RUN_TABLE . ' WHERE ' . $clause
        );
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** 各状态计数（管理端总览一次取齐）。 */
    public function countRunsByState(?string $namespace = null): array
    {
        $clause = $namespace !== null && $namespace !== ''
            ? ' WHERE namespace=' . $this->quote($namespace)
            : '';
        $rows = $this->db->query(
            'SELECT state, COUNT(*) AS n FROM ' . self::TASK_RUN_TABLE . $clause . ' GROUP BY state'
        );
        $out = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $out[(string) ($row['state'] ?? '')] = (int) ($row['n'] ?? 0);
        }
        return $out;
    }

    /** 认领排序取待执行实例：priority 高、plan_time 早优先。 */
    public function claimableRuns(string $nowIso, int $limit = 50): array
    {
        return $this->db->query(
            'SELECT * FROM ' . self::TASK_RUN_TABLE
            . ' WHERE state=' . $this->quote('queued')
            . ' AND plan_time<>' . $this->quote('')
            . ' AND plan_time<=' . $this->quote($nowIso)
            . ' ORDER BY priority DESC, plan_time ASC, id ASC'
            . ' LIMIT ' . $this->int($limit)
        );
    }

    // —— 周期定义（task_def） ——

    public function findDef(string $namespace, string $key): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TASK_DEF_TABLE
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND def_key=' . $this->quote($key) . ' LIMIT 1'
        );
        return $rows === [] ? null : $rows[0];
    }

    public function findDefById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TASK_DEF_TABLE . ' WHERE id=' . $this->int($id) . ' LIMIT 1'
        );
        return $rows === [] ? null : $rows[0];
    }

    /** 新增或覆盖定义（不改主键，历史 run 的 def_id 引用不失效）。 */
    public function upsertDef(array $row): void
    {
        $namespace = (string) ($row['namespace'] ?? '');
        $key = (string) ($row['def_key'] ?? '');
        $existing = $this->findDef($namespace, $key);
        $now = $this->quote(date('c'));
        $sets = [
            'handler=' . $this->quote((string) ($row['handler'] ?? '')),
            'mode=' . $this->quote((string) ($row['mode'] ?? 'interval')),
            'expr=' . $this->quote((string) ($row['expr'] ?? '')),
            'timezone=' . $this->quote((string) ($row['timezone'] ?? '')),
            'payload=' . $this->quote((string) ($row['payload'] ?? '')),
            'timeout_sec=' . $this->int($row['timeout_sec'] ?? 0),
            'next_run=' . $this->quote((string) ($row['next_run'] ?? '')),
            'status=' . $this->quote('active'),
            'enabled=1',
            'fail_streak=0',
            'last_error=' . $this->quote(''),
            'orphaned_at=' . $this->quote(''),
            'updated_at=' . $now,
        ];
        if ($existing !== null) {
            $this->db->exec(
                'UPDATE ' . self::TASK_DEF_TABLE . ' SET ' . implode(', ', $sets)
                . ' WHERE id=' . $this->int($existing['id'])
            );
            return;
        }
        $this->db->exec(
            'INSERT INTO ' . self::TASK_DEF_TABLE
            . ' (namespace, def_key, handler, mode, expr, timezone, payload, status, enabled, timeout_sec, '
            . 'next_run, created_by, created_at, updated_at) VALUES ('
            . $this->quote($namespace) . ', ' . $this->quote($key) . ', '
            . $this->quote((string) ($row['handler'] ?? '')) . ', '
            . $this->quote((string) ($row['mode'] ?? 'interval')) . ', '
            . $this->quote((string) ($row['expr'] ?? '')) . ', '
            . $this->quote((string) ($row['timezone'] ?? '')) . ', '
            . $this->quote((string) ($row['payload'] ?? '')) . ', '
            . $this->quote('active') . ', 1, ' . $this->int($row['timeout_sec'] ?? 0) . ', '
            . $this->quote((string) ($row['next_run'] ?? '')) . ', '
            . $this->quote((string) ($row['created_by'] ?? '')) . ', '
            . $now . ', ' . $now . ')'
        );
    }

    /** 到点定义：启用且 active 且 next_run 不晚于当前时刻。 */
    public function dueDefs(string $nowIso, int $limit): array
    {
        return $this->db->query(
            'SELECT * FROM ' . self::TASK_DEF_TABLE
            . ' WHERE enabled=1 AND status=' . $this->quote('active')
            . ' AND next_run<>' . $this->quote('')
            . ' AND next_run<=' . $this->quote($nowIso)
            . ' ORDER BY next_run ASC, id ASC LIMIT ' . $this->int($limit)
        );
    }

    /** 心跳推进定义：下次触发、最近结果、连续失败、启停。 */
    public function updateDef(int $id, array $fields): void
    {
        $allowed = [
            'next_run', 'last_run_at', 'last_status', 'last_error', 'fail_streak',
            'status', 'enabled', 'orphaned_at', 'timeout_sec', 'handler', 'payload', 'expr', 'mode',
        ];
        $sets = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = in_array($key, ['fail_streak', 'enabled', 'timeout_sec'], true)
                ? $key . '=' . $this->int($fields[$key])
                : $key . '=' . $this->quote((string) $fields[$key]);
        }
        $sets[] = 'updated_at=' . $this->quote(date('c'));
        $this->db->exec(
            'UPDATE ' . self::TASK_DEF_TABLE . ' SET ' . implode(', ', $sets)
            . ' WHERE id=' . $this->int($id)
        );
    }

    /** 设置定义启停；resume 时同时刷新 next_run。 */
    public function setDefEnabled(string $namespace, string $key, bool $enabled, string $nextRun = ''): bool
    {
        $def = $this->findDef($namespace, $key);
        if ($def === null) {
            return false;
        }
        $fields = ['enabled' => $enabled ? 1 : 0];
        if ($enabled && $nextRun !== '') {
            $fields['next_run'] = $nextRun;
        }
        if ($enabled) {
            $fields['status'] = 'active';
        }
        $this->updateDef((int) $def['id'], $fields);
        return true;
    }

    public function deleteDef(string $namespace, string $key): bool
    {
        $def = $this->findDef($namespace, $key);
        if ($def === null) {
            return false;
        }
        $this->db->exec(
            'DELETE FROM ' . self::TASK_DEF_TABLE . ' WHERE id=' . $this->int($def['id'])
        );
        return true;
    }

    public function listDefs(array $where, int $page, int $size): array
    {
        $clauses = [];
        foreach (['namespace', 'def_key', 'status'] as $key) {
            $value = trim((string) ($where[$key] ?? ''));
            if ($value !== '') {
                $clauses[] = $key . '=' . $this->quote($value);
            }
        }
        $filter = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        $offset = max(0, ($page - 1) * $size);
        $items = $this->db->query(
            'SELECT id, namespace, def_key, handler, mode, expr, timezone, status, enabled, timeout_sec, '
            . 'next_run, last_run_at, last_status, last_error, fail_streak, orphaned_at, created_at, updated_at '
            . 'FROM ' . self::TASK_DEF_TABLE . $filter
            . ' ORDER BY id DESC LIMIT ' . $this->int($size) . ' OFFSET ' . $this->int($offset)
        );
        $countRows = $this->db->query('SELECT COUNT(*) AS n FROM ' . self::TASK_DEF_TABLE . $filter);
        return [
            'items' => $items,
            'total' => (int) ($countRows[0]['n'] ?? 0),
            'page'  => $page,
            'size'  => $size,
        ];
    }

    public function countActiveDefs(string $namespace): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . self::TASK_DEF_TABLE
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND status<>' . $this->quote('orphaned')
        );
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** 包卸载：定义转孤儿并停用，实例打孤儿标记（保留记录）。 */
    public function orphanPackageRows(string $namespace, string $nowIso): void
    {
        $this->db->exec(
            'UPDATE ' . self::TASK_DEF_TABLE . ' SET enabled=0, status=' . $this->quote('orphaned')
            . ', orphaned_at=' . $this->quote($nowIso)
            . ', updated_at=' . $this->quote($nowIso)
            . ' WHERE namespace=' . $this->quote($namespace)
        );
        $this->db->exec(
            'UPDATE ' . self::TASK_RUN_TABLE . ' SET orphaned_at=' . $this->quote($nowIso)
            . ', updated_at=' . $this->quote($nowIso)
            . ' WHERE namespace=' . $this->quote($namespace) . ' AND orphaned_at=' . $this->quote('')
        );
    }

    /** 包重装/升级：解除孤儿标记，但保持 enabled=0（需显式 resume 或重新 define）。 */
    public function adoptPackageRows(string $namespace): void
    {
        $now = date('c');
        $this->db->exec(
            'UPDATE ' . self::TASK_DEF_TABLE . ' SET status=' . $this->quote('active')
            . ', orphaned_at=' . $this->quote('')
            . ', updated_at=' . $this->quote($now)
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND status=' . $this->quote('orphaned')
        );
        $this->db->exec(
            'UPDATE ' . self::TASK_RUN_TABLE . ' SET orphaned_at=' . $this->quote('')
            . ', updated_at=' . $this->quote($now)
            . ' WHERE namespace=' . $this->quote($namespace)
        );
    }

    // —— 提交幂等（task_idem） ——

    public function findIdem(string $namespace, string $idemKey, string $nowIso): ?string
    {
        $rows = $this->db->query(
            'SELECT run_id FROM ' . self::TASK_IDEM_TABLE
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND idem_key=' . $this->quote($idemKey)
            . ' AND expires_at>' . $this->quote($nowIso) . ' LIMIT 1'
        );
        return $rows === [] ? null : (string) ($rows[0]['run_id'] ?? '');
    }

    /** 写入幂等键；已存在返回既有 run_id。 */
    public function putIdem(string $namespace, string $idemKey, string $runId, string $expiresAt): string
    {
        $existing = $this->findIdem($namespace, $idemKey, date('c'));
        if ($existing !== null && $existing !== '') {
            return $existing;
        }
        $now = $this->quote(date('c'));
        try {
            $this->db->exec(
                'INSERT ' . ($this->db->dialect() === 'mysql' ? 'IGNORE ' : '')
                . 'INTO ' . self::TASK_IDEM_TABLE
                . ' (namespace, idem_key, run_id, created_at, expires_at) VALUES ('
                . $this->quote($namespace) . ', ' . $this->quote($idemKey) . ', '
                . $this->quote($runId) . ', ' . $now . ', ' . $this->quote($expiresAt) . ')'
            );
        } catch (\Throwable) {
            $row = $this->db->query(
                'SELECT run_id FROM ' . self::TASK_IDEM_TABLE
                . ' WHERE namespace=' . $this->quote($namespace)
                . ' AND idem_key=' . $this->quote($idemKey) . ' LIMIT 1'
            );
            return (string) ($row[0]['run_id'] ?? $runId);
        }
        return $runId;
    }

    public function purgeIdem(string $nowIso, int $limit = 500): int
    {
        return $this->db->execAffected(
            'DELETE FROM ' . self::TASK_IDEM_TABLE . ' WHERE expires_at<>' . $this->quote('')
            . ' AND expires_at<' . $this->quote($nowIso)
        );
    }

    // —— 心跳存活（task_heartbeat） ——

    public function heartbeatRow(string $worker = 'primary'): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TASK_HEARTBEAT_TABLE . ' WHERE worker=' . $this->quote($worker) . ' LIMIT 1'
        );
        return $rows === [] ? null : $rows[0];
    }

    /** 写心跳行（存在则更新，不存在则插入）。 */
    public function touchHeartbeat(array $row): void
    {
        $worker = (string) ($row['worker'] ?? 'primary');
        $now = $this->quote(date('c'));
        $existing = $this->heartbeatRow($worker);
        if ($existing !== null) {
            $this->db->exec(
                'UPDATE ' . self::TASK_HEARTBEAT_TABLE . ' SET '
                . 'pid=' . $this->int($row['pid'] ?? 0) . ', '
                . 'os=' . $this->quote((string) ($row['os'] ?? '')) . ', '
                . 'sapi=' . $this->quote((string) ($row['sapi'] ?? '')) . ', '
                . 'mode=' . $this->quote((string) ($row['mode'] ?? '')) . ', '
                . 'code_version=' . $this->quote((string) ($row['code_version'] ?? '')) . ', '
                . 'heartbeat_at=' . $now . ', '
                . 'last_tick_ms=' . $this->int($row['last_tick_ms'] ?? 0) . ', '
                . 'last_message=' . $this->quote((string) ($row['last_message'] ?? '')) . ' '
                . 'WHERE id=' . $this->int($existing['id'])
            );
            return;
        }
        $this->db->exec(
            'INSERT INTO ' . self::TASK_HEARTBEAT_TABLE
            . ' (worker, pid, os, sapi, mode, code_version, started_at, heartbeat_at, last_tick_ms, last_message) '
            . 'VALUES (' . $this->quote($worker) . ', ' . $this->int($row['pid'] ?? 0) . ', '
            . $this->quote((string) ($row['os'] ?? '')) . ', '
            . $this->quote((string) ($row['sapi'] ?? '')) . ', '
            . $this->quote((string) ($row['mode'] ?? '')) . ', '
            . $this->quote((string) ($row['code_version'] ?? '')) . ', '
            . $now . ', ' . $now . ', ' . $this->int($row['last_tick_ms'] ?? 0) . ', '
            . $this->quote((string) ($row['last_message'] ?? '')) . ')'
        );
    }

    // —— 运行态小状态（task_kv） ——

    public function kvGet(string $key): ?string
    {
        $rows = $this->db->query(
            'SELECT v FROM ' . self::TASK_KV_TABLE . ' WHERE k=' . $this->quote($key) . ' LIMIT 1'
        );
        return $rows === [] ? null : (string) ($rows[0]['v'] ?? '');
    }

    public function kvPut(string $key, string $value): void
    {
        $now = $this->quote(date('c'));
        if ($this->kvGet($key) === null) {
            $this->db->exec(
                'INSERT INTO ' . self::TASK_KV_TABLE . ' (k, v, updated_at) VALUES ('
                . $this->quote($key) . ', ' . $this->quote($value) . ', ' . $now . ')'
            );
            return;
        }
        $this->db->exec(
            'UPDATE ' . self::TASK_KV_TABLE . ' SET v=' . $this->quote($value)
            . ', updated_at=' . $now . ' WHERE k=' . $this->quote($key)
        );
    }

    public function kvDelete(string $key): void
    {
        $this->db->exec('DELETE FROM ' . self::TASK_KV_TABLE . ' WHERE k=' . $this->quote($key));
    }

    /** 按前缀列出小状态键（熔断中的 handler 列表用）。 */
    public function kvList(string $prefix): array
    {
        return $this->db->query(
            'SELECT k, v, updated_at FROM ' . self::TASK_KV_TABLE
            . ' WHERE k LIKE ' . $this->quote($prefix . '%') . ' ORDER BY k ASC'
        );
    }

    // —— 保留与清理 ——

    /** 删除终态且结束于阈值之前的实例，单次最多 limit 条。 */
    public function purgeTerminalRuns(string $beforeIso, int $limit = 500): int
    {
        $sql = 'DELETE FROM ' . self::TASK_RUN_TABLE
            . ' WHERE state IN (' . $this->quote('done') . ', ' . $this->quote('failed') . ', '
            . $this->quote('cancelled') . ')'
            . ' AND finished_at<>' . $this->quote('')
            . ' AND finished_at<' . $this->quote($beforeIso);
        return $this->db->execAffected($this->limitDelete($sql, $limit));
    }

    /** 单包保留最近 N 条终态，超出部分按 id 升序删除。 */
    public function trimRunsPerPackage(string $namespace, int $keep, int $limit = 200): int
    {
        $terminal = '(' . $this->quote('done') . ', ' . $this->quote('failed') . ', '
            . $this->quote('cancelled') . ')';
        $rows = $this->db->query(
            'SELECT id FROM ' . self::TASK_RUN_TABLE
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND state IN ' . $terminal
            . ' ORDER BY id DESC LIMIT ' . $this->int($keep) . ' OFFSET 0'
        );
        if ($rows === []) {
            return 0;
        }
        $lastKeepId = (int) $rows[count($rows) - 1]['id'];
        $sql = 'DELETE FROM ' . self::TASK_RUN_TABLE
            . ' WHERE namespace=' . $this->quote($namespace)
            . ' AND state IN ' . $terminal
            . ' AND id<' . $this->int($lastKeepId);
        return $this->db->execAffected($this->limitDelete($sql, $limit));
    }

    /** 单定义保留最近 N 轮（秒级定义额外只留最近 1 小时）。 */
    public function trimRunsPerDef(int $defId, int $keep, int $limit = 200): int
    {
        $rows = $this->db->query(
            'SELECT id FROM ' . self::TASK_RUN_TABLE
            . ' WHERE def_id=' . $this->int($defId)
            . ' ORDER BY id DESC LIMIT ' . $this->int($keep)
        );
        if ($rows === []) {
            return 0;
        }
        $lastKeepId = (int) $rows[count($rows) - 1]['id'];
        $sql = 'DELETE FROM ' . self::TASK_RUN_TABLE
            . ' WHERE def_id=' . $this->int($defId) . ' AND id<' . $this->int($lastKeepId);
        return $this->db->execAffected($this->limitDelete($sql, $limit));
    }

    /** MySQL 支持 DELETE ... LIMIT，其余方言退回普通 DELETE。 */
    private function limitDelete(string $sql, int $limit): string
    {
        return $this->db->dialect() === 'mysql' ? $sql . ' LIMIT ' . $this->int($limit) : $sql;
    }

    private function primaryKeySql(): string
    {
        return match ($this->db->dialect()) {
            'mysql' => 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    private function tableOptions(): string
    {
        return $this->db->dialect() === 'mysql' ? ' ENGINE=InnoDB' : '';
    }
}
