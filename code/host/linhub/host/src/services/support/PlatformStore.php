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
                . 'created_at VARCHAR(40) DEFAULT \'\', '
                . 'updated_at VARCHAR(40) DEFAULT \'\')'
                . $this->tableOptions()
            );
            if ($this->db->dialect() === 'mysql') {
                $this->db->exec('ALTER TABLE ' . self::APPR_FLOW_TABLE . ' ADD UNIQUE KEY uk_pkg_flow (package, flow_key)');
            }
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
