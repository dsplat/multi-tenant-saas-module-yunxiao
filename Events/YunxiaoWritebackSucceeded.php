<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 写回成功
 */
class YunxiaoWritebackSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $messageId,
        public readonly int $workitemId,
        public readonly string $externalWorkitemId,
        public readonly string $action,
    ) {}
}
