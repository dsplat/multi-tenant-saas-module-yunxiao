<?php

declare(strict_types=1);

/**
 * 云效 Region OpenAPI 连接器配置
 *
 * 连接本身（base_url / token / org_id / writeback_enabled）落 Integration
 * `integration_connections`，本文件只放模块级默认值与安全边界。
 */
return [
    'enabled' => true,

    // 路由固定挂载在 api/v1/yunxiao 下，与其他模块隔离；不提供可配前缀。

    // 列表默认每页条数
    'per_page' => 20,

    'api' => [
        // Region OpenAPI 根地址；Region 连接未填 base_url 时使用。
        'base_url' => 'https://devops.aliyuncs.com',
        // 中心站 OpenAPI 根地址；scope=Central / Account 且未填 base_url 时使用。
        'central_base_url' => 'https://openapi-rdc.aliyuncs.com',
        // token 传递方式：token => `x-yunxiao-token`，bearer => `Authorization: Bearer`
        'auth_style' => 'token',
        // 连接/请求超时（秒）
        'timeout' => 10,
        // 并发/速率提示（尊重云效配额，写回串行）
        'rate_limit' => 5,
        // 工作项分页大小
        'page_size' => 100,
        // 单次同步最多翻页数（防止失控）
        'max_pages' => 100,
    ],

    /*
     * SSRF 边界：只允许 https + 云效 Region / Central 接入点 host。
     * Region 是组织专属域 `*.devops.aliyuncs.com`；Central 固定 `openapi-rdc.aliyuncs.com`。
     * 测试可用 security.allow_private_hosts / allow_http 显式放开私有 host，
     * 生产环境不得打开（见需求 §6.11）。
     */
    'security' => [
        'allow_http' => false,
        'allow_private_hosts' => false,
        'allowed_host_suffixes' => [
            'devops.aliyuncs.com',
            'openapi-rdc.aliyuncs.com',
        ],
        'blocked_hosts' => [
            'localhost',
            'metadata',
            'metadata.google.internal',
            '169.254.169.254',
            '100.100.100.200',
        ],
    ],

    'sync' => [
        // 游标资源名：Integration integration_sync_cursors.resource
        'resource' => 'workitems',
        // 限流退避基数（毫秒）；测试置 0 可关闭等待
        'rate_limit_backoff_ms' => 1000,
        // 单页限流最多重试次数
        'max_rate_limit_retries' => 2,
        // 详情实时拉取超时（秒），超时回退缓存并标记 stale
        'detail_timeout' => 10,
        // 列表投影超过该分钟数视为过期（前端轮询 ≤15 分钟）
        'stale_minutes' => 15,
    ],

    'writeback' => [
        // 超过该尝试次数进死信
        'max_attempts' => 5,
        // 评论模板：只写模板化摘要，避免把代码/敏感信息写进云效
        'comment_template' => "【平台对账】{event}\n{summary}\n{url}",
        'comment_max_length' => 500,
    ],

    // token 过期提醒阈值（天）
    'token_expiry_warning_days' => 30,

    'custom_code' => [
        // Region 实测 4–6 位大写字母
        'pattern' => '/^[A-Z]{4,6}$/',
        'generated_length' => 6,
    ],

    /*
     * 需求导入云效（issue #1）
     *
     * 云效 CreateWorkitem 的 priority 不是顶层字段，而是 customFieldValues.priority，
     * 值必须是「工作项类型字段配置」里的优先级字段值 ID（如 888853d622cc8eae793e085e27），
     * 不是 high/medium 字面量。调用方既可直传字段值 ID，也可传业务别名；
     * 别名必须在此映射到真实字段值 ID，未配置的别名原样透传（由云效 422 拒绝，不猜测）。
     */
    'import' => [
        // 单次导入上限（表单与代码双重约束）
        'max_items' => 100,
        // 业务优先级别名 => 云效优先级字段值 ID
        'priority_map' => [
            // 'urgent' => '888853d622cc8eae793e085e27',
            // 'high' => '888853d622cc8eae793e085e28',
        ],
    ],

    /*
     * 项目成员对账（issue #44）
     *
     * 云效 CreateProjectMember（中心版 / Region 版）的 roleId 必填；这里给默认角色，
     * 调用方可在 members 写接口里用 role_id 覆盖。
     */
    'members' => [
        // 普通成员默认项目角色
        'role_id' => 'project.participant',
        // 项目负责人（project_manager）默认角色；负责人缺失时按此角色补加
        'manager_role_id' => 'project.admin',
        // 建项/重试/对账发现期望成员缺失时是否自动写云效补齐
        'auto_repair' => true,
        // syncMembers 默认是否 prune；默认 false，手工在云效加的成员永不被移除
        'prune' => false,
    ],
];
