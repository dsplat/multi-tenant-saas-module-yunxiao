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
        // Region OpenAPI 根地址；连接未填 base_url 时使用。Central 组织由 P1 driver 处理。
        'base_url' => 'https://devops.aliyuncs.com',
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
     * SSRF 边界：只允许 https + 云效 Region host。
     * 测试可用 security.allow_private_hosts / allow_http 显式放开私有 host，
     * 生产环境不得打开（见需求 §6.11）。
     */
    'security' => [
        'allow_http' => false,
        'allow_private_hosts' => false,
        'allowed_host_suffixes' => [
            'devops.aliyuncs.com',
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
];
