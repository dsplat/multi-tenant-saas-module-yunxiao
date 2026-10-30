<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\StoreTemplateMapRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\UpdateTemplateMapRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\TemplateMapResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoTemplateMapService;

/**
 * 模板/状态映射（保存前校验云效真实工作流）
 */
class TemplateMapController extends BaseController
{
    public function __construct(private readonly YunxiaoTemplateMapService $templates) {}

    public function index(): JsonResponse
    {
        $maps = array_map(
            fn ($map): array => (new TemplateMapResource($map))->resolve(),
            $this->templates->list($this->tenantId()),
        );

        return response()->json(['data' => ['data' => $maps, 'total' => count($maps)]]);
    }

    public function store(StoreTemplateMapRequest $request): JsonResponse
    {
        $map = $this->templates->upsert(
            $this->tenantId(),
            $request->validated(),
            $request->validated('connection_id'),
        );

        return $this->createdResponse((new TemplateMapResource($map))->resolve(), '模板映射已保存');
    }

    public function update(UpdateTemplateMapRequest $request, ?string $id = null): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenantId();

        if ($id !== null && $id !== '') {
            $existing = $this->templates->find($tenantId, (int) $id);
            $data['project_type'] = $data['project_type'] ?? $existing->project_type;
        }

        if (trim((string) ($data['project_type'] ?? '')) === '') {
            throw ValidationException::withMessages(['project_type' => 'project_type 不能为空']);
        }

        $map = $this->templates->upsert($tenantId, $data, $data['connection_id'] ?? null);

        return $this->successResponse((new TemplateMapResource($map))->resolve(), '模板映射已更新');
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
