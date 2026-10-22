<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\RotateTokenRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\StoreConnectionRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\UpdateConnectionRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\ConnectionResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoConnectionService;

/**
 * 云效连接管理（token 只进不出）
 */
class ConnectionController extends BaseController
{
    public function __construct(private readonly YunxiaoConnectionService $connections) {}

    public function index(): JsonResponse
    {
        $connections = array_map(
            fn (array $connection): array => (new ConnectionResource($connection))->resolve(),
            $this->connections->list($this->tenantId()),
        );

        return response()->json(['data' => ['data' => $connections, 'total' => count($connections)]]);
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        $connection = $this->connections->create($this->tenantId(), $request->validated());

        return $this->createdResponse(
            (new ConnectionResource($this->connections->sanitize($connection)))->resolve(),
            '云效连接已创建',
        );
    }

    public function update(UpdateConnectionRequest $request, string $id): JsonResponse
    {
        $connection = $this->connections->update($this->tenantId(), (int) $id, $request->validated());

        return $this->successResponse(
            (new ConnectionResource($this->connections->sanitize($connection)))->resolve(),
            '云效连接已更新',
        );
    }

    public function test(string $id): JsonResponse
    {
        return $this->successResponse($this->connections->test($this->tenantId(), (int) $id));
    }

    public function rotate(RotateTokenRequest $request, string $id): JsonResponse
    {
        $connection = $this->connections->rotate(
            $this->tenantId(),
            (int) $id,
            (string) $request->validated('token'),
            $request->validated('token_expires_at'),
        );

        return $this->successResponse(
            (new ConnectionResource($this->connections->sanitize($connection)))->resolve(),
            '云效 token 已轮换',
        );
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
