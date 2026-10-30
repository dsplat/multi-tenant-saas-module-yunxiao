<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 工作项投影写入完成（一条同步事件）
 */
class YunxiaoWorkitemSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $yunxiaoProjectId,
        public readonly int $workitemId,
        public readonly ?string $serialNumber,
        public readonly ?string $statusId,
        public readonly bool $unmapped,
    ) {}
}
