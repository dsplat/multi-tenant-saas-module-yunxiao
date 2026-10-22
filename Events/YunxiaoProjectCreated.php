<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 云效项目创建成功（映射与成员指派均成功）
 */
class YunxiaoProjectCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $hostProjectId,
        public readonly int $yunxiaoProjectId,
        public readonly string $externalProjectId,
        public readonly string $customCode,
        public readonly string $name,
    ) {}
}
