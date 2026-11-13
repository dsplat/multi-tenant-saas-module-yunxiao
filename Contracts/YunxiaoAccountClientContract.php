<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Contracts;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 云效账号级（Account）OpenAPI 客户端契约
 *
 * 账号级个人访问令牌访问中心站平台接口 `/oapi/v1/platform/...`：
 *  - 组织列表：`GET /organizations`
 *  - 当前用户：`GET /user`
 *  - 组织成员：`GET /organizations/{organizationId}/members`
 *  - 组织部门：`GET /organizations/{organizationId}/departments`
 *  - 组织角色：`GET /organizations/{organizationId}/roles`
 *
 * 归一化后的稳定键（snake_case）：
 *  - organization: organization_id / name
 *  - user: user_id / name / email / last_organization_id
 *  - member: user_id / name / email / status / role_id / role_ids / department_id / department_ids / joined_at
 *  - department: department_id / name / parent_id / creator_id / has_children
 *  - role: role_id / name / organization_id / permissions
 *
 * 边界：只读；邮箱可能缺失（实测 3/6 无邮箱），调用方需按「邮箱优先 + 姓名候选 + 管理员确认」映射。
 * operator ↔ 云效 userId 的映射表由宿主维护，本模块不建第二套账号体系。
 */
interface YunxiaoAccountClientContract
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listOrganizations(IntegrationConnection $connection, array $query = []): array;

    /**
     * @return array<string, mixed>
     */
    public function currentUser(IntegrationConnection $connection): array;

    /**
     * @param  array<string, mixed>  $query  page / per_page 或 max_results / next_token
     * @return list<array<string, mixed>>
     */
    public function listOrganizationMembers(IntegrationConnection $connection, string $organizationId, array $query = []): array;

    /**
     * @param  array<string, mixed>  $query  parent_id
     * @return list<array<string, mixed>>
     */
    public function listDepartments(IntegrationConnection $connection, string $organizationId, array $query = []): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listRoles(IntegrationConnection $connection, string $organizationId): array;
}
