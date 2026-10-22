<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemSyncService;

/**
 * 项目进度汇总（L1，仅聚合，不含成员隐私）
 */
class YunxiaoProgressSummaryHandler implements ToolHandlerContract
{
    public function __construct(private readonly YunxiaoWorkitemSyncService $workitems) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $summary = $this->workitems->summary($tenantId, array_filter([
            'project_id' => $arguments['project_id'] ?? null,
            'yunxiao_project_id' => $arguments['yunxiao_project_id'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));

        return [
            'source' => 'yunxiao_external_untrusted',
            'project_id' => $arguments['project_id'] ?? null,
            'total' => $summary['total'],
            'unmapped' => $summary['unmapped'],
            'statuses' => $summary['statuses'],
            'last_synced_at' => $summary['last_synced_at'],
            'note' => '仅聚合状态数量，不包含成员隐私字段',
        ];
    }
}
