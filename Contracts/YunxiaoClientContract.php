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
}
