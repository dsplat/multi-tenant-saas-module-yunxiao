<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\SyncWorkitemsRequest;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemSyncService;

/**
 * 手动触发工作项同步
 */
class SyncController extends BaseController
{
    public function __construct(private readonly YunxiaoWorkitemSyncService $workitems) {}

    public function store(SyncWorkitemsRequest $request): JsonResponse
    {
        $stats = $this->workitems->sync((int) TenantContext::getId(), array_filter([
            'project_id' => $request->validated('project_id'),
            'yunxiao_project_id' => $request->validated('yunxiao_project_id'),
            'connection_id' => $request->validated('connection_id'),
            'dry_run' => $request->boolean('dry_run'),
        ], static fn ($value): bool => $value !== null));

        return $this->successResponse($stats, $stats['partial'] ? '同步完成（部分失败）' : '同步完成');
    }
}
