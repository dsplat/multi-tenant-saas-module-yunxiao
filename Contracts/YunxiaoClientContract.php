<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Contracts;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 云效 projex OpenAPI 客户端契约（Region + Central 双站点）
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
 * 成员写操作（依据云效中心版 / Region 版 API 参考 CreateProjectMember / DeleteProjectMember /
 * ListProjectMembers；两版均未真实外呼实测，wire shape 由 ProjexYunxiaoClientTest 锁定）：
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
     * @param  array<string, mixed>  $query  page / per_page / updated_since / category
     * @return array{items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function listWorkitems(IntegrationConnection $connection, string $externalProjectId, array $query = []): array;

    /**
     * 迭代（Sprint）列表 —— 官方 `ListSprints`：`GET /projects/{id}/sprints`（中心版拼 organizations）。
     *
     * 只读；返回归一化字段：
     *  - external_sprint_id / name / status / status_name / start_date / end_date /
     *    capacity_hours / locked / description / owners[{id,name}] / creator{id,name} /
     *    gmt_created / gmt_modified
     * `status` 原样保留云效取值 `TODO|DOING|ARCHIVED`（未开始/进行中/已完成），
     * `status_name` 是稳定中文映射，便于下游直接展示；未知取值不猜，回落为原值。
     *
     * 迭代进度（工作项完成情况）不在此接口内：云效没有迭代统计接口，
     * 由服务层 `YunxiaoExecutionService::progress()` 按工作项聚合，
     * 调用方按工作项上的 `iteration_id` 归属。
     *
     * @param  array<string, mixed>  $query  status（string 或 list<string>）/ name / page / per_page
     * @return array{items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function listSprints(IntegrationConnection $connection, string $externalProjectId, array $query = []): array;

    /**
     * 里程碑（Milestone）列表 —— 官方 `ListMilestones`：`GET /projects/{id}/milestones`。
     *
     * 只读；返回归一化字段：
     *  - external_milestone_id / name / status_id / status_name / status_display_name /
     *    status_name_en / plan_end_date / actual_end_date / assigned_to / assigned_to_name /
     *    description / creator{id,name} / gmt_created / gmt_modified
     *
     * @param  array<string, mixed>  $query  status / page / per_page
     * @return array{items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function listMilestones(IntegrationConnection $connection, string $externalProjectId, array $query = []): array;

    /**
     * 风险项列表 —— 云效没有独立「风险」资源：风险是工作项的一个大类
     * （工作项 `category=Risk`）。等价于 `listWorkitems(..., ['category' => 'Risk'])`。
     *
     * 条目字段与 `listWorkitems` 完全一致（含 external_workitem_id / subject /
     * status_id / status_name / assigned_to / iteration_id / category / due_date）。
     *
     * @param  array<string, mixed>  $query  page / per_page / updated_since
     * @return array{items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function listRisks(IntegrationConnection $connection, string $externalProjectId, array $query = []): array;

    /**
     * 创建工作项（需求/任务/缺陷等；`POST /workitems`）。
     *
     * 云效必填：`spaceId`（externalProjectId）、`subject`、`assignedTo`、`workitemTypeId`。
     * `priority` 不是顶层字段，实现写入 `customFieldValues.priority`（值为云效字段值 ID）。
     * `status` 不在创建 body 内：调用方拿 `external_workitem_id` 后再调 updateWorkitem。
     *
     * @param  array<string, mixed>  $payload  title|subject / description / assigned_to|assignedTo /
     *                                         workitem_type_id|workitemTypeId / priority /
     *                                         custom_field_values / parent_id
     * @return array<string, mixed> 归一化工作项，至少含 external_workitem_id
     */
    public function createWorkitem(IntegrationConnection $connection, string $externalProjectId, array $payload): array;

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
