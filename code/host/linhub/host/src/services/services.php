<?php

declare(strict_types=1);

/*
 * 平台服务清单（G2）——服务实现类/版本/changelog 的唯一权威来源。
 *
 * 约定：
 *  - list: id => [contract(接口类), impl(实现类，可空), version(语义版本), source, type(服务类型 functional=功能服务|hosted=托管服务), desc, data_driver(数据随包驱动类，可省略；声明后参与数据随包导出与导入)]
 *  - changelog: 'id@version' => [时间, 说明]；供 Admin 治理页展示升级历史。
 *
 * ServiceRegistry.bootstrap() 与 ServiceInjector 均以此文件为数据源，
 * 平台不再硬编码 HOST_SERVICES 常量。
 */

return [
    'list' => [
        'logging' => [
            'contract' => 'linhub\\host\\services\\logging\\Logging',
            'impl'     => 'linhub\\host\\services\\logging\\LoggingService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '日志服务',
        ],
        'config' => [
            'contract' => 'linhub\\host\\services\\config\\Config',
            'impl'     => 'linhub\\host\\services\\config\\ConfigService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '配置服务',
        ],
        'cache' => [
            'contract' => 'linhub\\host\\services\\cache\\Cache',
            'impl'     => 'linhub\\host\\services\\cache\\CacheService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '缓存服务',
        ],
        'id' => [
            'contract' => 'linhub\\host\\services\\id\\IdGenerator',
            'impl'     => 'linhub\\host\\services\\id\\SequentialIdGenerator',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => 'ID 生成器',
        ],
        'filestore' => [
            'contract' => 'linhub\\host\\services\\filestore\\FileStore',
            'impl'     => null,
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '文件存储（仅接口）',
        ],
        'messaging' => [
            'contract' => 'linhub\\host\\services\\messaging\\Messaging',
            'impl'     => 'linhub\\host\\services\\messaging\\MessagingService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '消息/事件：发布/订阅（共享进程内事件总线 Broker，功能包可发布自身事件、订阅本方/跨包/宿主生命周期广播）',
        ],
        'scheduler' => [
            'contract' => 'linhub\\host\\services\\scheduler\\TaskScheduler',
            'impl'     => 'linhub\\host\\services\\scheduler\\SchedulerService',
            'version'  => '1.1.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '异步任务托管：一次性与周期任务、队列与三级并发闸门、租约回收、取消、幂等、熔断、Lite/Pro 双档执行',
        ],
        'attachment' => [
            'contract' => 'linhub\\host\\services\\attachment\\Attachment',
            'impl'     => 'linhub\\host\\services\\attachment\\AttachmentService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '附件托管：随机 Token 存取、公开/私有鉴权、本地/七牛存储工厂、默认图片与过期孤儿清理',
            'data_driver' => 'linhub\\host\\services\\attachment\\AttachmentDataDriver',
        ],
        'dictionary' => [
            'contract' => 'linhub\\host\\services\\dictionary\\Dictionary',
            'impl'     => 'linhub\\host\\services\\dictionary\\DictionaryService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '数据字典：键值/JSON 持久化、功能包命名空间隔离、公开只读与托管方写删',
            'data_driver' => 'linhub\\host\\services\\dictionary\\DictionaryDataDriver',
        ],
        'loginstate' => [
            'contract' => 'linhub\\host\\services\\loginstate\\LoginState',
            'impl'     => 'linhub\\host\\services\\loginstate\\LoginStateService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '登录状态：当前用户/游客态、tags、角色类型只读快照',
        ],
        'inbox' => [
            'contract' => 'linhub\\host\\services\\inbox\\Inbox',
            'impl'     => 'linhub\\host\\services\\inbox\\InboxService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '站内消息：按用户/角色/用户组投递并快照去重、收件箱、已读管理、投递记录',
        ],
        'llmgateway' => [
            'contract' => 'linhub\\host\\services\\llmgateway\\LlmGateway',
            'impl'     => 'linhub\\host\\services\\llmgateway\\LlmGatewayService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => 'LLM 网关：OpenAI 兼容协议适配、同步/流式 chat、embedding、双维度配额、全量审计日志',
            'data_driver' => 'linhub\\host\\services\\llmgateway\\LlmGatewayDataDriver',
        ],
        'package' => [
            'contract' => 'linhub\\host\\services\\package\\PackageProbe',
            'impl'     => 'linhub\\host\\services\\package\\PackageProbeService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '功能包探测：查询指定功能包是否已安装/已启用/版本号/已装包清单，只读无异常',
        ],
        'auth' => [
            'contract' => 'linhub\\host\\services\\auth\\RbacQuery',
            'impl'     => 'linhub\\host\\services\\auth\\RbacQueryService',
            'version'  => '1.2.0',
            'source'   => 'host',
            'type'     => 'functional',
            'desc'     => '账号与权限服务：当前登录快照、按 id/refId 查用户、用户/角色/权限组/权限点清单、用户最终权限点、用户是否拥有某权限点（只读无异常）；角色仅 5 个系统预设角色、不可增删改（V1.2 移除 ensureRole）',
            'data_driver' => 'linhub\\host\\services\\auth\\AuthDataDriver',
        ],
        'translation' => [
            'contract' => 'linhub\\host\\services\\translation\\Translator',
            'impl'     => 'linhub\\host\\services\\translation\\TranslatorService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '翻译服务：多语言词条（namespace+key+lang）、回退链与 {name} 占位符、包间读写隔离、全量 map 与 60s 进程缓存、包内 translations/ 种子随安装导入/升级覆盖/卸载清除',
            'data_driver' => 'linhub\\host\\services\\translation\\TranslationDataDriver',
        ],
        'recyclebin' => [
            'contract' => 'linhub\\host\\services\\recyclebin\\RecycleBin',
            'impl'     => 'linhub\\host\\services\\recyclebin\\RecycleBinService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '回收站服务：任意结构数据托管（namespace+token 定位）、恢复返回副本由包自行写回、彻底删除与批量清除、配额（单条 1MB/单包 1000 条）、ttlDays 到期惰性清理、卸载孤儿态与重装接管、恢复/删除操作记录',
        ],
        'approval' => [
            'contract' => 'linhub\\host\\services\\approval\\Approval',
            'impl'     => 'linhub\\host\\services\\approval\\ApprovalService',
            'version'  => '1.2.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '审批服务：DSL 流程模板托管、流程模板列表（flows，本包+系统流程只读）、审批单提交与快照、串行 any/all、排他/并行网关与汇合、自定义审批人解析器、双入口审批、事件与站内信通知、设置更新（updateSettings）、卸载孤儿态与重装接管、平台审批中心',
            'data_driver' => 'linhub\\host\\services\\approval\\ApprovalDataDriver',
        ],
        'printtpl' => [
            'contract' => 'linhub\\host\\services\\printtpl\\PrintTpl',
            'impl'     => 'linhub\\host\\services\\printtpl\\PrintTplService',
            'version'  => '1.0.0',
            'source'   => 'host',
            'type'     => 'hosted',
            'desc'     => '单据打印模板服务：平台托管可打印模板（可视化编辑器+源码模式、纸张设置、预览试打）、按编号渲染（占位符/each/if、date/money 格式化、明细表跨页表头重复、页眉页脚页码、合并与分组小计、二维码条码组件）、双轨归属（平台公共+包级私有）、printUrl 一次性票据、服务端 PDF 生成（headless 浏览器）、版本快照回滚、配额、卸载孤儿态与重装接管、数据随包导出导入',
            'data_driver' => 'linhub\\host\\services\\printtpl\\PrintTplDataDriver',
        ],
    ],

    'changelog' => [
        'logging_1.0.0' => ['2026-10-09', '服务治理改造，日志服务独立垂直目录'],
        'config_1.0.0' => ['2026-10-09', '服务治理改造，配置服务独立垂直目录'],
        'cache_1.0.0' => ['2026-10-09', '服务治理改造，缓存服务独立垂直目录'],
        'id_1.0.0' => ['2026-10-09', '服务治理改造，ID 生成器独立垂直目录'],
        'filestore_1.0.0' => ['2026-10-09', '服务治理改造，文件存储独立垂直目录（仅接口）'],
        'messaging_1.0.0' => ['2026-10-21', '消息/事件服务落地实现：共享事件总线 Broker；功能包可发布自身事件、订阅本方/跨包/宿主生命周期广播（安装/升级/卸载/打包）'],
        'scheduler_1.0.0' => ['2026-10-09', '服务治理改造，任务调度独立垂直目录（仅接口）'],
        'attachment_1.0.0' => ['2026-10-11', '新增附件托管服务（AT-1~AT-12）'],
        'dictionary_1.0.0' => ['2026-10-11', '新增数据字典服务（DD-1~DD-9）'],
        'loginstate_1.0.0' => ['2026-10-11', '新增登录状态服务（LS-1~LS-6）'],
        'inbox_1.0.0' => ['2026-10-11', '新增站内消息服务（IM-1~IM-15）'],
        'llmgateway_1.0.0' => ['2026-10-19', '新增 LLM 网关服务（OpenAI 兼容、chat/stream/embed、配额、审计）'],
        'package_1.0.0'    => ['2026-10-19', '新增功能包探测服务（PackageProbe：安装/启用/版本/快照/已装清单）'],
        'auth_1.0.0'        => ['2026-10-20', '新增账号与权限只读查询服务（RbacQuery：用户/角色/权限组/权限点/当前登录快照，只读无异常）'],
        'auth_1.1.0'        => ['2026-10-21', '新增 ensureRole：功能包接入角色（按角色名判重，无则创建、有则跳过；SUPER_ADMIN 禁止，失败如实返回 error）'],
        'auth_1.2.0'        => ['2026-11-10', '角色模型收缩：仅 5 个系统预设角色（工作者/审核员/功能管理员/系统管理员/超级管理员）、不可增删改，移除 ensureRole 写方法；权限项 roles 只能落预设角色英文键或为空（不限角色）'],
        'translation_1.0.0' => ['2026-10-24', '新增翻译服务（TR-1~TR-6）：多语言词条行式存储、回退链、占位符、包间读写隔离、全量 map + 60s 进程缓存、包内 translations/ 种子安装导入/升级全量覆盖/卸载清除'],
        'recyclebin_1.0.0' => ['2026-10-27', '新增回收站服务（RB-1~RB-8）：任意结构数据托管、恢复返回副本、彻底删除、配额、ttlDays 到期惰性清理、卸载孤儿态与重装接管、恢复/删除操作记录'],
        'approval_1.0.0' => ['2026-10-28', '新增审批服务（AP-1~AP-16）：DSL 流程模板（串行 any/all、排他/并行网关、汇合）、审批单双快照、自定义审批人解析器、双入口审批、整单驳回、事件与站内信、卸载孤儿态与重装接管、平台审批中心'],
        'approval_1.1.0' => ['2026-10-31', '新增 updateSettings 契约方法：功能包可更新审批服务设置（白名单合并语义，与平台服务设置页同源）'],
        'scheduler_1.1.0' => ['2026-11-10', '任务调度落地为异步任务托管服务：提交/周期定义/暂停恢复/立即触发/状态查询/取消/列表，三级并发闸门、租约回收、幂等与熔断、Lite/Pro 双档执行、tasks.php 处理器声明、平台调度中心'],
    ],
];