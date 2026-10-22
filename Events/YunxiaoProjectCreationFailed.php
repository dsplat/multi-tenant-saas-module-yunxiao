<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 云效项目创建失败（未产生半截映射，可重试）
 */
class YunxiaoProjectCreationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $hostProjectId,
        public readonly string $name,
        public readonly string $reason,
        public readonly string $error,
    ) {}
}
