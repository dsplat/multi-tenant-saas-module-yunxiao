<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Exceptions;

/**
 * 配置/前置条件错误（fail closed）
 *
 * reason 用于 API/测试稳定断言，message 可直接给运营看。
 */
final class YunxiaoConfigurationException extends YunxiaoException
{
    public const REASON_MISSING_TEMPLATE_MAP = 'missing_template_map';

    public const REASON_TEMPLATE_NOT_FOUND = 'template_not_found';

    public const REASON_INVALID_WORKITEM_TYPE = 'invalid_workitem_type';

    public const REASON_INVALID_STATUS = 'invalid_status';

    public const REASON_INVALID_CUSTOM_CODE = 'invalid_custom_code';

    public const REASON_CUSTOM_CODE_CONFLICT = 'custom_code_conflict';

    public const REASON_CONNECTION_NOT_FOUND = 'connection_not_found';

    public const REASON_CONNECTION_DISABLED = 'connection_disabled';

    public const REASON_TOKEN_MISSING = 'token_missing';

    public const REASON_TOKEN_EXPIRED = 'token_expired';

    public const REASON_WRITEBACK_DISABLED = 'writeback_disabled';

    public const REASON_UNSAFE_BASE_URL = 'unsafe_base_url';

    public const REASON_INVALID_EVENT = 'invalid_event';

    public const REASON_ALREADY_SENT = 'already_sent';

    public const REASON_PROJECT_NOT_FOUND = 'project_not_found';

    public function __construct(string $message, public readonly string $reason = 'configuration_error')
    {
        parent::__construct($message, 422);
    }

    public static function unsafeBaseUrl(string $detail): self
    {
        return new self('云效连接地址不安全：' . $detail, self::REASON_UNSAFE_BASE_URL);
    }

    public static function writebackDisabled(): self
    {
        return new self('云效连接未开启写回（config.writeback_enabled=false），已拒绝写回', self::REASON_WRITEBACK_DISABLED);
    }

    public static function tokenExpired(): self
    {
        return new self('云效 token 已过期，写回已停止；投影仍可只读访问', self::REASON_TOKEN_EXPIRED);
    }

    public static function missingTemplateMap(string $projectType): self
    {
        return new self(
            sprintf('项目类型 %s 未配置云效模板映射（且无显式默认映射）；请先在模板映射中配置，平台不会静默套用默认模板', $projectType),
            self::REASON_MISSING_TEMPLATE_MAP,
        );
    }
}
