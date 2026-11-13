<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Contracts;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 云效 Region OpenAPI 客户端契约
 *
 * 实现只负责 HTTP + 字段归一化；连接/凭据由 IntegrationConnection 提供。
 * 测试注入 fake 实现，禁止在测试中真实外呼。
 *
 * 返回结构（归一化后的稳定键，均为 snake_case）：
 *  - project: external_project_id / name / custom_code / description / scope / template_id / status / metadata
 *  - workitem: external_workitem_id / serial_number / workitem_type_id / subject / status_id /
 *              status_name / assigned_to / gmt_created / gmt_modified / description
 *  - template: template_id / name / workitem_types[{id,name}] / statuses[{id,name,workitem_type_id}]
 *  - member: id / name
 *
 * 成员写操作（依据云效 Region 版 API 参考 CreateProjectMember / DeleteProjectMember /
 * ListProjectMembers；实例侧未真实外呼实测，wire shape 由 RegionYunxiaoClientTest 锁定）：
 *  - 添加：body `{roleId, userIds[]}`，roleId 必填（`project.participant` / `project.admin` 等）；
 *  - 移除：DELETE 仍要求 body `{userId, roleIds[]?}`（不是路径参数）；
 *  - 两个接口成功时**返回空响应体**，实现不得按 JSON 解析失败处理。
 * 成员「期望集合对账」不放在客户端，由 YunxiaoProjectService::syncMembers() 负责。
 */
interface YunxiaoClientContract
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listProjects(IntegrationConnection $connection, array $query = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getProject(IntegrationConnection $connection, string $externalProjectId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProject(IntegrationConnection $connection, array $payload): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(IntegrationConnection $connection): array;

    /**
     * @param  array<string, mixed>  $query  page / per_page / updated_since
     * @return array{items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function listWorkitems(IntegrationConnection $connection, string $externalProjectId, array $query = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getWorkitem(IntegrationConnection $connection, string $externalWorkitemId): array;

    /**
     * @param  array<string, mixed>  $payload  status_id
     * @return array<string, mixed>
     */
    public function updateWorkitem(IntegrationConnection $connection, string $externalWorkitemId, array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function addComment(IntegrationConnection $connection, string $externalWorkitemId, string $comment): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listMembers(IntegrationConnection $connection, string $externalProjectId): array;

    /**
     * 批量添加项目成员（`POST /projects/{id}/members`）。
     *
     * @param  list<string>  $userIds
     * @param  array{role_id?:string|null, operator_id?:string|null}  $options  role_id 缺省 project.participant
     */
    public function addMembers(IntegrationConnection $connection, string $externalProjectId, array $userIds, array $options = []): void;

    /**
     * 移除单个项目成员（`DELETE /projects/{id}/members`，云效要求 DELETE 带 body）。
     *
     * @param  array{role_ids?:list<string>|null, operator_id?:string|null}  $options  省略 roleIds 时移除该用户全部项目角色
     */
    public function removeMember(IntegrationConnection $connection, string $externalProjectId, string $userId, array $options = []): void;
}
