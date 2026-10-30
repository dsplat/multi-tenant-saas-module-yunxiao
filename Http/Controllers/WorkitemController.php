<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\WorkitemResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemSyncService;

/**
 * 工作项投影（只读；任何修改请求明确不支持）
 */
class WorkitemController extends BaseController
{
    public function __construct(private readonly YunxiaoWorkitemSyncService $workitems) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_filter([
            'project_id' => $request->query('project_id'),
            'yunxiao_project_id' => $request->query('yunxiao_project_id'),
            'serial_number' => $request->query('serial_number'),
            'external_workitem_id' => $request->query('external_workitem_id'),
            'status_id' => $request->query('status_id'),
            'keyword' => $request->query('keyword'),
            'unmapped' => $request->has('unmapped') ? $request->boolean('unmapped') : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $paginator = $this->workitems->paginate(
            $this->tenantId(),
            $filters,
            min(100, max(1, $request->integer('per_page', (int) config('yunxiao.per_page', 20)))),
        );

        return response()->json([
            'data' => [
                'data' => array_map(
                    static fn ($item): array => (new WorkitemResource($item))->resolve(),
                    $paginator->items(),
                ),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $refresh = $request->has('refresh') ? $request->boolean('refresh') : true;
        $result = $this->workitems->detail($this->tenantId(), (int) $id, $refresh);

        return $this->successResponse([
            'workitem' => (new WorkitemResource($result['workitem']))->resolve(),
            'stale' => $result['stale'],
            'error' => $result['error'],
        ]);
    }

    public function update(string $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => '云效工作项在平台只读：请在云效中修改（平台不提供编辑接口，也不写实际工时）',
            'reason' => 'unsupported',
        ], 422);
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
