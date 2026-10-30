<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\StoreProjectRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\ProjectResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoProjectService;

/**
 * H1 云效项目投影
 */
class ProjectController extends BaseController
{
    public function __construct(private readonly YunxiaoProjectService $projects) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->projects->paginate($this->tenantId(), array_filter([
            'project_id' => $request->query('project_id'),
            'external_project_id' => $request->query('external_project_id'),
            'status' => $request->query('status'),
            'keyword' => $request->query('keyword'),
        ], static fn ($value): bool => $value !== null && $value !== ''), $this->perPage($request));

        return response()->json([
            'data' => [
                'data' => array_map(
                    static fn ($project): array => (new ProjectResource($project))->resolve(),
                    $paginator->items(),
                ),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $result = $this->projects->create($this->tenantId(), $request->validated());

        return $this->createdResponse([
            'project' => (new ProjectResource($result['project']))->resolve(),
            'created' => $result['created'],
            'mapping_status' => $result['mapping_status'],
            'assignment_failures' => $result['assignment_failures'],
        ], $result['created'] ? '云效项目已创建' : '云效项目已存在（幂等返回）');
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', (int) config('yunxiao.per_page', 20))));
    }
}
