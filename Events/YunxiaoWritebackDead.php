<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 写回进入死信（超过重试上限，等待人工处理）
 */
class YunxiaoWritebackDead
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
