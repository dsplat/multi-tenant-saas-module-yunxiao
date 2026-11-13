<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoAccountClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;

/**
 * 云效账号级组织能力（issue #46）
 *
 * 只负责「选连接 + 组织白名单 + 调客户端」；operator ↔ 云效 userId 的映射表由宿主维护，
 * 本服务不建账号体系，只输出归一化成员数据（user_id / email / status / role_ids / department_ids）。
 */
class YunxiaoOrganizationService
{
    public function __construct(
        private readonly YunxiaoAccountClientContract $client,
        private readonly YunxiaoConnectionService $connections,
    ) {}

    /**
     * @return array{connection_id:int, organizations:list<array<string, mixed>>}
     */
    public function organizations(int $tenantId, ?int $connectionId = null): array
    {
        $connection = $this->accountConnection($tenantId, $connectionId);

        return [
            'connection_id' => (int) $connection->connection_id,
            'organizations' => $this->client->listOrganizations($connection),
        ];
    }

    /**
     * @return array{connection_id:int, user:array<string, mixed>}
     */
    public function currentUser(int $tenantId, ?int $connectionId = null): array
    {
        $connection = $this->accountConnection($tenantId, $connectionId);

        return [
            'connection_id' => (int) $connection->connection_id,
            'user' => $this->client->currentUser($connection),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{connection_id:int, organization_id:string, members:list<array<string, mixed>>}
     */
    public function members(int $tenantId, string $organizationId, array $query = [], ?int $connectionId = null): array
    {
        $connection = $this->accountConnection($tenantId, $connectionId);
        $organizationId = $this->assertAllowedOrganization($connection, $organizationId);

        return [
            'connection_id' => (int) $connection->connection_id,
            'organization_id' => $organizationId,
            'members' => $this->client->listOrganizationMembers($connection, $organizationId, $query),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{connection_id:int, organization_id:string, departments:list<array<string, mixed>>}
     */
    public function departments(int $tenantId, string $organizationId, array $query = [], ?int $connectionId = null): array
    {
        $connection = $this->accountConnection($tenantId, $connectionId);
        $organizationId = $this->assertAllowedOrganization($connection, $organizationId);

        return [
            'connection_id' => (int) $connection->connection_id,
            'organization_id' => $organizationId,
            'departments' => $this->client->listDepartments($connection, $organizationId, $query),
        ];
    }

    /**
     * @return array{connection_id:int, organization_id:string, roles:list<array<string, mixed>>}
     */
    public function roles(int $tenantId, string $organizationId, ?int $connectionId = null): array
    {
        $connection = $this->accountConnection($tenantId, $connectionId);
        $organizationId = $this->assertAllowedOrganization($connection, $organizationId);

        return [
            'connection_id' => (int) $connection->connection_id,
            'organization_id' => $organizationId,
            'roles' => $this->client->listRoles($connection, $organizationId),
        ];
    }

    private function accountConnection(int $tenantId, ?int $connectionId): IntegrationConnection
    {
        return $this->connections->activeAccountConnection($tenantId, $connectionId);
    }

    private function assertAllowedOrganization(IntegrationConnection $connection, string $organizationId): string
    {
        $organizationId = trim($organizationId);

        if ($organizationId === '') {
            throw new YunxiaoConfigurationException(
                'organizationId 不能为空',
                YunxiaoConfigurationException::REASON_ORG_MISSING,
            );
        }

        $allowlist = array_values(array_filter(
            array_map(static fn ($id): string => trim((string) $id), (array) (($connection->config ?? [])['org_allowlist'] ?? [])),
            static fn (string $id): bool => $id !== '',
        ));

        if ($allowlist !== [] && ! in_array($organizationId, $allowlist, true)) {
            throw new YunxiaoConfigurationException(
                '云效组织不在连接的允许列表内（config.org_allowlist）',
                YunxiaoConfigurationException::REASON_ORG_NOT_ALLOWED,
            );
        }

        return $organizationId;
    }
}
