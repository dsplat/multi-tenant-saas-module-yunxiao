<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 云效连接检查完成（成功/失败）
 */
class YunxiaoConnectionChecked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $connectionId,
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {}
}
