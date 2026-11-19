<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\ProjectResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoExecutionService;

/**
 * 项目执行读取（issue #48）：迭代 / 里程碑 / 风险 / 进度汇总。
 *
 * 全部只读，受 capability `common.yunxiao.workitem.view` 保护（复用既有只读能力，
 * 不新增权限项，避免二次授权迁移）。`{id}` 是本模块的 YunxiaoProject 投影主键，
 * 不是云效项目 ID：外部 ID 只从本租户投影解析，隔离由服务层保证。
 */
class ExecutionController extends BaseController
{
    public function __construct(private readonly YunxiaoExecutionService $execution) {}

    public function sprints(Request $request, string $id): JsonResponse
    {
        $result = $this->execution->sprints($this->tenantId(), (int) $id, $this->listQuery($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'project' => (new ProjectResource($result['project']))->resolve(),
            'sprints' => $result['items'],
            'has_more' => $result['has_more'],
            'total' => $result['total'],
        ]);
    }

    public function milestones(Request $request, string $id): JsonResponse
    {
        $result = $this->execution->milestones($this->tenantId(), (int) $id, $this->listQuery($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'project' => (new ProjectResource($result['project']))->resolve(),
            'milestones' => $result['items'],
            'has_more' => $result['has_more'],
            'total' => $result['total'],
        ]);
    }

    public function risks(Request $request, string $id): JsonResponse
    {
        $result = $this->execution->risks($this->tenantId(), (int) $id, $this->listQuery($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'project' => (new ProjectResource($result['project']))->resolve(),
            'risks' => $result['items'],
            'has_more' => $result['has_more'],
            'total' => $result['total'],
        ]);
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        $result = $this->execution->progress($this->tenantId(), (int) $id, array_filter([
            'categories' => $this->arrayQuery($request, 'categories'),
            'done_status_ids' => $this->arrayQuery($request, 'done_status_ids'),
            'done_status_names' => $this->arrayQuery($request, 'done_status_names'),
            'page_size' => $request->query('page_size'),
            'max_pages' => $request->query('max_pages'),
        ], static fn ($value): bool => $value !== null && $value !== []));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'project' => (new ProjectResource($result['project']))->resolve(),
            'external_project_id' => $result['external_project_id'],
            'generated_at' => $result['generated_at'],
            'categories' => $result['categories'],
            'total' => $result['total'],
            'by_category' => $result['by_category'],
            'by_status' => $result['by_status'],
            'unassigned_count' => $result['unassigned_count'],
            'completion' => $result['completion'],
            'overdue_count' => $result['overdue_count'],
            'truncated' => $result['truncated'],
            'partial' => $result['partial'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listQuery(Request $request): array
    {
        $query = array_filter([
            'status' => $request->query('status'),
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $query;
    }

    /**
     * 数组型 query：同时接受 `?x[]=a&x[]=b` 与 `?x=a,b`。
     *
     * @return list<string>
     */
    private function arrayQuery(Request $request, string $key): array
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_map(static fn ($item): string => trim((string) $item), $items);

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
