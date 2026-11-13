<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\AddProjectMembersRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\SyncProjectMembersRequest;
use MultiTenantSaas\Modules\Yunxiao\Http\Resources\ProjectResource;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoProjectService;

/**
 * 云效项目成员（issue #44）
 *
 * - GET 只读远端成员列表；
 * - POST 添加成员、DELETE 移除成员、POST .../members/sync 按期望集合对账；
 * - 写操作统一受 capability `common.yunxiao.member.sync` 保护；
 * - 成员 ID 是云效 userId，平台账号 → 云效账号的映射由宿主负责，本模块不做账号体系转换。
 */
class ProjectMemberController extends BaseController
{
    public function __construct(private readonly YunxiaoProjectService $projects) {}

    public function index(string $id): JsonResponse
    {
        $result = $this->projects->members($this->tenantId(), (int) $id);

        return $this->successResponse([
            'project' => (new ProjectResource($result['project']))->resolve(),
            'members' => $result['members'],
        ]);
    }

    public function store(AddProjectMembersRequest $request, string $id): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->projects->addMembers(
            $this->tenantId(),
            (int) $id,
            (array) $payload['user_ids'],
            $payload['role_id'] ?? null,
        );

        return $this->successResponse($this->memberResult($result), '成员已添加到云效项目');
    }

    public function destroy(string $id, string $userId): JsonResponse
    {
        $result = $this->projects->removeMember($this->tenantId(), (int) $id, $userId);

        return $this->successResponse($this->memberResult($result), '成员已从云效项目移除');
    }

    public function sync(SyncProjectMembersRequest $request, string $id): JsonResponse
    {
        $result = $this->projects->syncMembers($this->tenantId(), (int) $id, $request->validated());

        return $this->successResponse($this->memberResult($result), '成员对账完成');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function memberResult(array $result): array
    {
        return [
            'project' => (new ProjectResource($result['project']))->resolve(),
            'added' => array_values((array) ($result['added'] ?? [])),
            'removed' => array_values((array) ($result['removed'] ?? [])),
            'failed_removals' => array_values((array) ($result['failed_removals'] ?? [])),
            'assignment_failures' => array_values((array) ($result['failures'] ?? [])),
        ];
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
