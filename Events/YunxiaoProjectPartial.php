<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 云效项目部分成功（项目已建，映射待补或成员指派失败；不自动删除云效项目）
 */
class YunxiaoProjectPartial
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $failures  失败项（成员 ID 或映射标记）
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $hostProjectId,
        public readonly ?int $yunxiaoProjectId,
        public readonly ?string $externalProjectId,
        public readonly string $reason,
        public readonly array $failures = [],
    ) {}
}
