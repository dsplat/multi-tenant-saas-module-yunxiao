<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitem;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemSyncService;

/**
 * 工作项只读查询（L1，投影数据，不外呼）
 */
class YunxiaoWorkitemQueryHandler implements ToolHandlerContract
{
    public function __construct(private readonly YunxiaoWorkitemSyncService $workitems) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($arguments['per_page'] ?? 20)));

        $paginator = $this->workitems->paginate($tenantId, array_filter([
            'project_id' => $arguments['project_id'] ?? null,
            'yunxiao_project_id' => $arguments['yunxiao_project_id'] ?? null,
            'serial_number' => $arguments['serial_number'] ?? null,
            'status_id' => $arguments['status_id'] ?? null,
            'keyword' => $arguments['keyword'] ?? null,
            'unmapped' => $arguments['unmapped'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''), $perPage, $page);

        return [
            'source' => 'yunxiao_external_untrusted',
            'page' => $page,
            'per_page' => $perPage,
            'total' => $paginator->total(),
            'items' => array_map(
                static fn (YunxiaoWorkitem $item): array => [
                    'yunxiao_workitem_id' => (int) $item->yunxiao_workitem_id,
                    'serial_number' => $item->serial_number,
                    'subject' => $item->subject,
                    'workitem_type_id' => $item->workitem_type_id,
                    'status_id' => $item->status_id,
                    'status_name' => $item->status_name,
                    'unmapped' => $item->isUnmapped(),
                    'gmt_modified' => $item->gmt_modified?->format('Y-m-d H:i:s'),
                    'fetched_at' => $item->fetched_at?->format('Y-m-d H:i:s'),
                ],
                $paginator->items(),
            ),
        ];
    }
}
