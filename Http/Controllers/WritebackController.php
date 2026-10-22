<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\WritebackResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWritebackService;

/**
 * 写回记录与重试（Integration outbox）
 */
class WritebackController extends BaseController
{
    public function __construct(private readonly YunxiaoWritebackService $writeback) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->writeback->paginate($this->tenantId(), array_filter([
            'status' => $request->query('status'),
            'action' => $request->query('action'),
            'connection_id' => $request->query('connection_id'),
        ], static fn ($value): bool => $value !== null && $value !== ''), min(100, max(1, $request->integer('per_page', (int) config('yunxiao.per_page', 20)))));

        return response()->json([
            'data' => [
                'data' => array_map(
                    static fn ($message): array => (new WritebackResource($message))->resolve(),
                    $paginator->items(),
                ),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        $message = $this->writeback->retry($this->tenantId(), (int) $id);
        $stats = $this->writeback->process($this->tenantId(), (int) $message->connection_id, 50);
        $message->refresh();

        return $this->successResponse([
            'writeback' => (new WritebackResource($message))->resolve(),
            'process' => $stats,
        ], '写回已重试');
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
