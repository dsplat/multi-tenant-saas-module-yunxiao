<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 写回失败（未达死信阈值，已安排退避重试）
 */
class YunxiaoWritebackFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $messageId,
        public readonly int $workitemId,
        public readonly int $attempts,
        public readonly string $error,
    ) {}
}
