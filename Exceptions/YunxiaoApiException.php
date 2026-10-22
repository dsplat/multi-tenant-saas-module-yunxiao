<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Exceptions;

/**
 * 云效 API 调用失败（已归一化，不含 token/原始响应体）
 */
final class YunxiaoApiException extends YunxiaoException
{
    public const REASON_AUTH_FAILED = 'auth_failed';

    public const REASON_FORBIDDEN = 'forbidden';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_CONFLICT = 'conflict';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_INVALID_ARGUMENT = 'invalid_argument';

    public const REASON_UNAVAILABLE = 'unavailable';

    public const REASON_UNKNOWN = 'unknown';

    public function __construct(
        string $message,
        int $statusCode,
        public readonly string $reason = self::REASON_UNKNOWN,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 401 => new self('云效鉴权失败：token 无效或已过期', 422, self::REASON_AUTH_FAILED),
            $status === 403 => new self('云效拒绝访问：组织/项目权限不足', 422, self::REASON_FORBIDDEN),
            $status === 404 => new self('云效资源不存在', 404, self::REASON_NOT_FOUND),
            $status === 409 => new self('云效资源冲突（customCode 或乐观锁）', 409, self::REASON_CONFLICT),
            $status === 429 => new self('云效限流：请稍后重试', 503, self::REASON_RATE_LIMITED, true),
            in_array($status, [400, 422], true) => new self('云效参数校验失败', 422, self::REASON_INVALID_ARGUMENT),
            $status >= 500 => new self('云效服务暂不可用', 503, self::REASON_UNAVAILABLE, true),
            default => new self('云效 API 请求失败（HTTP ' . $status . '）', 502, self::REASON_UNKNOWN),
        };
    }
}
