<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoOrganizationService;

/**
 * 云效账号级组织能力（issue #46）：组织列表、当前用户、组织成员、部门、角色。
 *
 * 全部只读；需要 `scope=Account` 的账号级连接，受 capability
 * `common.yunxiao.organization.view` 保护。`org_allowlist` 在服务与客户端双重校验。
 */
class OrganizationController extends BaseController
{
    public function __construct(private readonly YunxiaoOrganizationService $organizations) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->organizations->organizations($this->tenantId(), $this->connectionId($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'organizations' => $result['organizations'],
            'total' => count($result['organizations']),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $result = $this->organizations->currentUser($this->tenantId(), $this->connectionId($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'user' => $result['user'],
        ]);
    }

    public function members(Request $request, string $orgId): JsonResponse
    {
        $result = $this->organizations->members(
            $this->tenantId(),
            $orgId,
            $this->pagingQuery($request),
            $this->connectionId($request),
        );

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'organization_id' => $result['organization_id'],
            'members' => $result['members'],
            'total' => count($result['members']),
        ]);
    }

    public function departments(Request $request, string $orgId): JsonResponse
    {
        $result = $this->organizations->departments(
            $this->tenantId(),
            $orgId,
            array_filter(['parent_id' => $request->query('parent_id')], static fn ($value): bool => $value !== null && $value !== ''),
            $this->connectionId($request),
        );

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'organization_id' => $result['organization_id'],
            'departments' => $result['departments'],
            'total' => count($result['departments']),
        ]);
    }

    public function roles(Request $request, string $orgId): JsonResponse
    {
        $result = $this->organizations->roles($this->tenantId(), $orgId, $this->connectionId($request));

        return $this->successResponse([
            'connection_id' => $result['connection_id'],
            'organization_id' => $result['organization_id'],
            'roles' => $result['roles'],
            'total' => count($result['roles']),
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function pagingQuery(Request $request): array
    {
        $query = [];

        if ($request->filled('page')) {
            $query['page'] = max(1, $request->integer('page'));
        }

        if ($request->filled('per_page')) {
            $query['per_page'] = min(100, max(1, $request->integer('per_page')));
        }

        if ($request->filled('max_results')) {
            $query['max_results'] = max(1, $request->integer('max_results'));
        }

        if ($request->filled('next_token')) {
            $query['next_token'] = (string) $request->query('next_token');
        }

        return $query;
    }

    private function connectionId(Request $request): ?int
    {
        return $request->filled('connection_id') ? (int) $request->query('connection_id') : null;
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
