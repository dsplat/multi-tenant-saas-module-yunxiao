<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoAccountClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;

/**
 * 云效账号级（Account）客户端：`/oapi/v1/platform/...` 只读接口。
 *
 * 需要 `scope=Account` 的账号级个人访问令牌（中心站 `openapi-rdc.aliyuncs.com`）。
 * `config.org_allowlist` 非空时是硬边界：列表过滤 + 单组织接口拒绝未列出的组织；
 * `config.default_org_id` 只用于项目级 projex 调用，本类不隐式补组织。
 */
class PlatformYunxiaoClient extends ProjexYunxiaoClient implements YunxiaoAccountClientContract
{
    private const PLATFORM_PREFIX = '/oapi/v1/platform';

    public function listOrganizations(IntegrationConnection $connection, array $query = []): array
    {
        $this->assertAccount($connection);

        $data = $this->request($connection, 'GET', self::PLATFORM_PREFIX . '/organizations');

        $organizations = array_values(array_map(
            fn (array $item): array => [
                'organization_id' => (string) $this->pick($item, ['id', 'organizationId', 'organization_id']),
                'name' => (string) $this->pick($item, ['name', 'organizationName', 'organization_name']),
            ],
            $this->itemsOf($data),
        ));

        $allowlist = $this->allowlist($connection);

        if ($allowlist === []) {
            return $organizations;
        }

        return array_values(array_filter(
            $organizations,
            static fn (array $organization): bool => in_array($organization['organization_id'], $allowlist, true),
        ));
    }

    public function currentUser(IntegrationConnection $connection): array
    {
        $this->assertAccount($connection);

        $data = $this->request($connection, 'GET', self::PLATFORM_PREFIX . '/user');
        $user = $this->itemsOf($data)[0] ?? [];

        return [
            'user_id' => (string) $this->pick($user, ['id', 'userId', 'user_id']),
            'name' => (string) $this->pick($user, ['name', 'nickName', 'username', 'displayName']),
            'email' => $this->nullableString($this->pick($user, ['email', 'emailAddress', 'email_address'])),
            'last_organization_id' => $this->nullableString($this->pick($user, ['lastOrganization', 'last_organization_id'])),
        ];
    }

    public function listOrganizationMembers(IntegrationConnection $connection, string $organizationId, array $query = []): array
    {
        $organizationId = $this->assertOrganization($connection, $organizationId);

        $data = $this->request(
            $connection,
            'GET',
            $this->organizationPath($organizationId) . '/members',
            $this->pagingQuery($query, 50),
        );

        return array_values(array_map(
            fn (array $item): array => $this->normalizeMember($item),
            $this->itemsOf($data),
        ));
    }

    public function listDepartments(IntegrationConnection $connection, string $organizationId, array $query = []): array
    {
        $organizationId = $this->assertOrganization($connection, $organizationId);

        $parentId = $query['parent_id'] ?? $query['parentId'] ?? null;

        $data = $this->request(
            $connection,
            'GET',
            $this->organizationPath($organizationId) . '/departments',
            array_filter([
                'parentId' => $parentId !== null && trim((string) $parentId) !== '' ? trim((string) $parentId) : null,
            ], static fn ($value): bool => $value !== null),
        );

        return array_values(array_map(
            fn (array $item): array => [
                'department_id' => (string) $this->pick($item, ['id', 'departmentId', 'department_id']),
                'name' => (string) $this->pick($item, ['name', 'departmentName']),
                'parent_id' => $this->nullableString($this->pick($item, ['parentId', 'parent_id'])),
                'creator_id' => $this->nullableString($this->pick($item, ['creatorId', 'creator_id'])),
                'has_children' => (bool) ($item['hasSub'] ?? $item['has_sub'] ?? $item['hasChildren'] ?? false),
            ],
            $this->itemsOf($data),
        ));
    }

    public function listRoles(IntegrationConnection $connection, string $organizationId): array
    {
        $organizationId = $this->assertOrganization($connection, $organizationId);

        $data = $this->request($connection, 'GET', $this->organizationPath($organizationId) . '/roles');

        return array_values(array_map(
            fn (array $item): array => [
                'role_id' => (string) $this->pick($item, ['id', 'roleId', 'role_id']),
                'name' => (string) $this->pick($item, ['name', 'roleName']),
                'organization_id' => $this->nullableString($this->pick($item, ['organizationId', 'organization_id'])) ?? $organizationId,
                'permissions' => array_values(array_filter(
                    array_map('strval', (array) ($item['permissions'] ?? [])),
                    static fn (string $permission): bool => $permission !== '',
                )),
            ],
            $this->itemsOf($data),
        ));
    }

    private function assertAccount(IntegrationConnection $connection): void
    {
        if (! self::isAccount($connection)) {
            throw new YunxiaoConfigurationException(
                '组织/成员/部门/角色接口需要 scope=Account 的账号级云效连接',
                YunxiaoConfigurationException::REASON_SCOPE_MISMATCH,
            );
        }
    }

    private function assertOrganization(IntegrationConnection $connection, string $organizationId): string
    {
        $this->assertAccount($connection);

        $organizationId = trim($organizationId);

        if ($organizationId === '') {
            throw new YunxiaoConfigurationException(
                'organizationId 不能为空',
                YunxiaoConfigurationException::REASON_ORG_MISSING,
            );
        }

        $allowlist = $this->allowlist($connection);

        if ($allowlist !== [] && ! in_array($organizationId, $allowlist, true)) {
            throw new YunxiaoConfigurationException(
                '云效组织不在连接的允许列表内（config.org_allowlist）',
                YunxiaoConfigurationException::REASON_ORG_NOT_ALLOWED,
            );
        }

        return $organizationId;
    }

    private function organizationPath(string $organizationId): string
    {
        return self::PLATFORM_PREFIX . '/organizations/' . rawurlencode($organizationId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, int|string>
     */
    private function pagingQuery(array $query, int $defaultPerPage): array
    {
        if (isset($query['max_results']) && (string) $query['max_results'] !== '') {
            $paging = ['maxResults' => max(1, (int) $query['max_results'])];

            if (isset($query['next_token']) && trim((string) $query['next_token']) !== '') {
                $paging['nextToken'] = trim((string) $query['next_token']);
            }

            return $paging;
        }

        return [
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => min(100, max(1, (int) ($query['per_page'] ?? $defaultPerPage))),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeMember(array $item): array
    {
        $roleIds = $this->stringList($item['roleIds'] ?? $item['role_ids'] ?? []);
        $departmentIds = $this->stringList($item['deptIds'] ?? $item['dept_ids'] ?? $item['departmentIds'] ?? []);

        return [
            'user_id' => (string) $this->pick($item, ['userId', 'user_id', 'id', 'identifier']),
            'name' => (string) $this->pick($item, ['name', 'userName', 'displayName', 'display_name']),
            'email' => $this->nullableString($this->pick($item, ['email', 'emailAddress', 'email_address'])),
            'status' => $this->nullableString($this->pick($item, ['status'])),
            'role_id' => $roleIds[0] ?? null,
            'role_ids' => $roleIds,
            'department_id' => $departmentIds[0] ?? null,
            'department_ids' => $departmentIds,
            'joined_at' => $this->nullableString($this->pick($item, ['joined', 'joinedAt', 'joined_at'])),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), (array) $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function allowlist(IntegrationConnection $connection): array
    {
        return $this->stringList(($connection->config ?? [])['org_allowlist'] ?? []);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
