<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Modules\Integration\Models\ExternalMapping;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Services\ExternalMappingService;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoProjectCreated;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoProjectCreationFailed;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoProjectPartial;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;

/**
 * H1 建云效项目
 *
 * 幂等键 = 宿主 project_id：
 * 1. 先查本地映射/项目投影，已存在直接返回（不重复建项目）；
 * 2. customCode 优先宿主编码，不合规时稳定生成，冲突显式报错不静默换码；
 * 3. 写映射（external_mappings）失败记录孤儿外部 ID，可重试且不自动删除云效项目；
 * 4. 成员/PM 指派失败标记 partial 并记录失败项。
 */
class YunxiaoProjectService
{
    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
        private readonly YunxiaoTemplateMapService $templates,
        private readonly ExternalMappingService $mappings,
    ) {}

    /**
     * @param  array{project_id:int, name:string, project_type:string, custom_code?:string|null, scope?:string|null, description?:string|null, project_manager?:string|null, members?:list<string>, role_id?:string|null, connection_id?:int|null}  $input
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>, added:list<string>}
     */
    public function create(int $tenantId, array $input): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $input): array {
            $hostProjectId = (int) ($input['project_id'] ?? 0);
            $name = trim((string) ($input['name'] ?? ''));

            if ($hostProjectId <= 0 || $name === '') {
                throw new YunxiaoConfigurationException('project_id 与 name 均不能为空');
            }

            $existing = YunxiaoProject::query()
                ->where('tenant_id', $tenantId)
                ->where('project_id', $hostProjectId)
                ->first();

            if ($existing instanceof YunxiaoProject) {
                return $this->resume($tenantId, $existing, $input);
            }

            // 映射已存在但投影缺失（历史/人工清理）：直接复用外部项目，不重复创建。
            $mapping = $this->projectMapping($tenantId, $hostProjectId);

            if ($mapping instanceof ExternalMapping) {
                $project = YunxiaoProject::query()
                    ->where('tenant_id', $tenantId)
                    ->where('external_project_id', $mapping->source_id)
                    ->first();

                if ($project instanceof YunxiaoProject) {
                    return $this->resume($tenantId, $project, $input);
                }
            }

            $connection = $this->connections->activeConnection($tenantId, isset($input['connection_id']) ? (int) $input['connection_id'] : null);
            $map = $this->templates->resolve($tenantId, (string) ($input['project_type'] ?? ''));
            $customCode = $this->resolveCustomCode($tenantId, $hostProjectId, $input['custom_code'] ?? null);

            $payload = [
                'name' => $name,
                'custom_code' => $customCode,
                'template_id' => $map->template_id,
                'scope' => $input['scope'] ?? null,
                'description' => $input['description'] ?? null,
                'project_manager' => $input['project_manager'] ?? null,
                'members' => array_values(array_filter(array_map('strval', (array) ($input['members'] ?? [])))),
            ];

            try {
                $created = $this->client->createProject($connection, $payload);
            } catch (YunxiaoApiException $e) {
                $reason = $e->reason === YunxiaoApiException::REASON_CONFLICT
                    ? YunxiaoConfigurationException::REASON_CUSTOM_CODE_CONFLICT
                    : $e->reason;

                $error = $e->reason === YunxiaoApiException::REASON_CONFLICT
                    ? sprintf('customCode %s 在云效已存在，请人工确认后再试（平台不会静默换码）', $customCode)
                    : $e->getMessage();

                Event::dispatch(new YunxiaoProjectCreationFailed($tenantId, $hostProjectId, $name, $reason, $error));

                if ($e->reason === YunxiaoApiException::REASON_CONFLICT) {
                    throw new YunxiaoConfigurationException($error, $reason);
                }

                throw $e;
            }

            $externalProjectId = (string) ($created['external_project_id'] ?? '');

            if ($externalProjectId === '') {
                $error = '云效建项目返回缺少项目 ID';
                Event::dispatch(new YunxiaoProjectCreationFailed($tenantId, $hostProjectId, $name, 'missing_external_id', $error));

                throw new YunxiaoException($error);
            }

            $expected = $this->expectedAssignments($input);

            $project = new YunxiaoProject([
                'tenant_id' => $tenantId,
                'connection_id' => (int) $connection->connection_id,
                'project_id' => $hostProjectId,
                'external_project_id' => $externalProjectId,
                'name' => $name,
                'custom_code' => $customCode,
                'template_id' => $map->template_id,
                'scope' => $input['scope'] ?? null,
                'status' => YunxiaoProject::STATUS_ACTIVE,
                'metadata' => array_filter([
                    'project_type' => $input['project_type'],
                    'project_manager' => trim((string) ($input['project_manager'] ?? '')) ?: null,
                    'expected_assignments' => $expected !== [] ? $expected : null,
                    // 建项时随 createProject 一起提交的成员属于「平台添加」，prune 时才是可回收集合。
                    'managed_members' => $expected !== [] ? $expected : null,
                    'mapping_pending' => false,
                ], static fn ($value): bool => $value !== null),
            ]);
            $project->save();

            try {
                $this->mappings->upsert(
                    $tenantId,
                    YunxiaoConnectionService::PROVIDER,
                    'project',
                    $externalProjectId,
                    'project',
                    $hostProjectId,
                    [
                        'name' => $name,
                        'custom_code' => $customCode,
                        'template_id' => $map->template_id,
                    ],
                );
            } catch (\Throwable) {
                $project->status = YunxiaoProject::STATUS_CREATION_FAILED;
                $project->metadata = array_merge((array) $project->metadata, [
                    'mapping_pending' => true,
                    'orphan_external_id' => $externalProjectId,
                    'mapping_error' => '外部映射写入失败',
                ]);
                $project->save();

                Event::dispatch(new YunxiaoProjectPartial(
                    $tenantId,
                    $hostProjectId,
                    (int) $project->yunxiao_project_id,
                    $externalProjectId,
                    'mapping_failed',
                    ['mapping:' . $externalProjectId],
                ));

                return [
                    'project' => $project->refresh(),
                    'created' => true,
                    'mapping_status' => 'pending',
                    'assignment_failures' => [],
                ];
            }

            return $this->verifyAssignments($tenantId, $project, $connection, $expected, [
                'repair' => (bool) config('yunxiao.members.auto_repair', true),
                'manager' => $input['project_manager'] ?? null,
                'role_id' => $input['role_id'] ?? null,
            ], true);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $query = YunxiaoProject::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        return $query
            ->when($filters['project_id'] ?? null, fn ($builder, $value) => $builder->where('project_id', (int) $value))
            ->when($filters['external_project_id'] ?? null, fn ($builder, $value) => $builder->where('external_project_id', (string) $value))
            ->when($filters['status'] ?? null, fn ($builder, $value) => $builder->where('status', (string) $value))
            ->when($filters['keyword'] ?? null, fn ($builder, $value) => $builder->where('name', 'like', '%' . $value . '%'))
            ->orderByDesc('created_at')
            ->orderByDesc('yunxiao_project_id')
            ->paginate($perPage ?? (int) config('yunxiao.per_page', 20));
    }

    public function find(int $tenantId, int $yunxiaoProjectId): YunxiaoProject
    {
        $query = YunxiaoProject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($yunxiaoProjectId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        $project = $query->first();

        if (! $project instanceof YunxiaoProject) {
            throw new NotFoundException('云效项目不存在');
        }

        return $project;
    }

    /**
     * 重试成员/PM 指派核验：按 metadata 里的期望集合补齐缺失成员（issue #44）。
     *
     * @return list<string> 仍缺失的成员 ID
     */
    public function retryAssignments(int $tenantId, int $yunxiaoProjectId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId): array {
            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $expected = array_values(array_map('strval', (array) ($project->metadata['expected_assignments'] ?? [])));
            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected, [
                'repair' => true,
                'manager' => $project->metadata['project_manager'] ?? null,
            ]);

            return $result['assignment_failures'];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>, added:list<string>}
     */
    private function resume(int $tenantId, YunxiaoProject $project, array $input): array
    {
        $project = $project->refresh();

        if ($project->isMappingPending()) {
            try {
                $this->mappings->upsert(
                    $tenantId,
                    YunxiaoConnectionService::PROVIDER,
                    'project',
                    (string) $project->external_project_id,
                    'project',
                    (int) $project->project_id,
                    [
                        'name' => $project->name,
                        'custom_code' => $project->custom_code,
                        'template_id' => $project->template_id,
                    ],
                );

                $metadata = (array) $project->metadata;
                $metadata['mapping_pending'] = false;
                unset($metadata['mapping_error'], $metadata['orphan_external_id']);
                $project->metadata = $metadata;

                if ($project->status === YunxiaoProject::STATUS_CREATION_FAILED) {
                    $project->status = YunxiaoProject::STATUS_ACTIVE;
                }

                $project->save();
            } catch (\Throwable) {
                return [
                    'project' => $project,
                    'created' => false,
                    'mapping_status' => 'pending',
                    'assignment_failures' => $project->assignmentFailures(),
                    'added' => [],
                ];
            }
        }

        // 已存在项目再次携带成员清单 = 成员/PM 变更请求（issue #44）：按新期望补齐，不重建项目。
        $hasMemberIntent = array_key_exists('members', $input) || array_key_exists('project_manager', $input);
        $storedExpected = $this->normalizeMemberIds((array) ($project->metadata['expected_assignments'] ?? []));
        $requestedExpected = $hasMemberIntent ? $this->requestedAssignments($project, $input) : [];

        if ($hasMemberIntent && $requestedExpected !== $storedExpected) {
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $result = $this->verifyAssignments($tenantId, $project, $connection, $requestedExpected, [
                'repair' => (bool) config('yunxiao.members.auto_repair', true),
                'manager' => array_key_exists('project_manager', $input)
                    ? ($input['project_manager'] ?? null)
                    : ($project->metadata['project_manager'] ?? null),
                'role_id' => $input['role_id'] ?? null,
            ]);

            return [
                'project' => $result['project'],
                'created' => false,
                'mapping_status' => $result['mapping_status'],
                'assignment_failures' => $result['assignment_failures'],
                'added' => $result['added'],
            ];
        }

        if ($project->status === YunxiaoProject::STATUS_PARTIAL) {
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $expected = array_values(array_map('strval', (array) ($project->metadata['expected_assignments'] ?? [])));
            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected, [
                'repair' => true,
                'manager' => $project->metadata['project_manager'] ?? null,
            ]);

            return [
                'project' => $result['project'],
                'created' => false,
                'mapping_status' => $result['mapping_status'],
                'assignment_failures' => $result['assignment_failures'],
                'added' => $result['added'],
            ];
        }

        return [
            'project' => $project,
            'created' => false,
            'mapping_status' => 'ok',
            'assignment_failures' => [],
            'added' => [],
        ];
    }

    /**
     * 远端成员列表（只读）。
     *
     * @return array{project: YunxiaoProject, members: list<array<string, mixed>>}
     */
    public function members(int $tenantId, int $yunxiaoProjectId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId): array {
            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);

            return [
                'project' => $project,
                'members' => $this->client->listMembers($connection, (string) $project->external_project_id),
            ];
        });
    }

    /**
     * 显式添加成员（写云效并合并进期望集合）。
     *
     * @param  list<string>  $userIds
     * @return array{project: YunxiaoProject, added: list<string>, removed: list<string>, failures: list<string>}
     */
    public function addMembers(int $tenantId, int $yunxiaoProjectId, array $userIds, ?string $roleId = null): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $userIds, $roleId): array {
            $userIds = $this->normalizeMemberIds($userIds);

            if ($userIds === []) {
                throw new YunxiaoConfigurationException('成员列表不能为空', YunxiaoConfigurationException::REASON_MEMBER_LIST_EMPTY);
            }

            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);

            $this->client->addMembers($connection, (string) $project->external_project_id, $userIds, [
                'role_id' => $this->memberRoleId($roleId),
            ]);

            $metadata = (array) $project->metadata;
            $metadata['expected_assignments'] = $this->mergeMemberIds((array) ($metadata['expected_assignments'] ?? []), $userIds);
            $metadata['managed_members'] = $this->mergeMemberIds((array) ($metadata['managed_members'] ?? []), $userIds);
            $project->metadata = $metadata;
            $project->save();

            $result = $this->verifyAssignments($tenantId, $project, $connection, $metadata['expected_assignments'], [
                'repair' => false,
                // 刚写成功但远端列表可能短暂滞后：不能因为一次读不到就把新成员记成失败。
                'known_present' => $userIds,
            ]);

            return [
                'project' => $result['project'],
                'added' => $userIds,
                'removed' => [],
                'failures' => $result['assignment_failures'],
            ];
        });
    }

    /**
     * 显式移除成员（写云效并从期望集合摘除）。
     *
     * @return array{project: YunxiaoProject, added: list<string>, removed: list<string>, failures: list<string>}
     */
    public function removeMember(int $tenantId, int $yunxiaoProjectId, string $userId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $userId): array {
            $userId = trim($userId);

            if ($userId === '') {
                throw new YunxiaoConfigurationException('成员 ID 不能为空', YunxiaoConfigurationException::REASON_MEMBER_LIST_EMPTY);
            }

            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);

            $this->client->removeMember($connection, (string) $project->external_project_id, $userId);

            $metadata = (array) $project->metadata;
            $expected = $this->withoutMemberIds((array) ($metadata['expected_assignments'] ?? []), [$userId]);
            $metadata['expected_assignments'] = $expected;
            $metadata['managed_members'] = $this->withoutMemberIds((array) ($metadata['managed_members'] ?? []), [$userId]);
            $metadata['assignment_failures'] = $this->withoutMemberIds((array) ($metadata['assignment_failures'] ?? []), [$userId]);
            $project->metadata = $metadata;
            $project->save();

            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected, ['repair' => false]);

            return [
                'project' => $result['project'],
                'added' => [],
                'removed' => [$userId],
                'failures' => $result['assignment_failures'],
            ];
        });
    }

    /**
     * 成员/PM 对账：补齐期望集合中缺失的成员；`prune=true` 时只移除
     * 「平台添加过且已不在期望集合」的成员，手工在云效加入的成员永不被移除。
     *
     * @param  array{members?:list<string>, project_manager?:string|null, role_id?:string|null, prune?:bool}  $input
     * @return array{project: YunxiaoProject, added: list<string>, removed: list<string>, failed_removals: list<string>, failures: list<string>}
     */
    public function syncMembers(int $tenantId, int $yunxiaoProjectId, array $input = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $input): array {
            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $metadata = (array) $project->metadata;

            $hasExplicitExpected = array_key_exists('members', $input) || array_key_exists('project_manager', $input);
            $expected = $hasExplicitExpected
                ? $this->requestedAssignments($project, $input)
                : $this->normalizeMemberIds((array) ($metadata['expected_assignments'] ?? []));

            if ($expected === []) {
                throw new YunxiaoConfigurationException(
                    '成员期望集合为空：请显式传 members/project_manager，或先由建项写入 expected_assignments',
                    YunxiaoConfigurationException::REASON_MEMBER_LIST_EMPTY,
                );
            }

            $removed = [];
            $failedRemovals = [];
            $prune = (bool) ($input['prune'] ?? config('yunxiao.members.prune', false));

            if ($prune) {
                $managed = $this->normalizeMemberIds((array) ($metadata['managed_members'] ?? []));
                $stale = array_values(array_diff($managed, $expected));

                foreach ($stale as $staleUserId) {
                    try {
                        $this->client->removeMember($connection, (string) $project->external_project_id, $staleUserId);
                        $removed[] = $staleUserId;
                    } catch (\Throwable) {
                        // 单个移除失败不放弃整轮：留在 managed_members 里，下次对账重试。
                        $failedRemovals[] = $staleUserId;
                    }
                }

                $metadata['managed_members'] = $this->withoutMemberIds($managed, $removed);
            }

            $project->metadata = $metadata;
            $project->save();

            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected, [
                'repair' => true,
                'manager' => $hasExplicitExpected ? ($input['project_manager'] ?? null) : ($metadata['project_manager'] ?? null),
                'role_id' => $input['role_id'] ?? null,
            ]);

            return [
                'project' => $result['project'],
                'added' => $result['added'],
                'removed' => $removed,
                'failed_removals' => $failedRemovals,
                'failures' => $result['assignment_failures'],
            ];
        });
    }

    /**
     * 核验成员并按需补齐（issue #44：resume / 建项后成员缺失时有修复路径）。
     *
     * 列表读不到时不补写（无法计算差集），按原口径记为失败。
     *
     * @param  list<string>  $expected
     * @param  array{repair?:bool, manager?:string|null, role_id?:string|null, known_present?:list<string>}  $options
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>, added:list<string>}
     */
    private function verifyAssignments(
        int $tenantId,
        YunxiaoProject $project,
        IntegrationConnection $connection,
        array $expected,
        array $options = [],
        bool $created = false,
    ): array {
        $expected = $this->normalizeMemberIds($expected);
        $manager = trim((string) ($options['manager'] ?? ''));
        $manager = $manager === '' ? null : $manager;
        $repair = (bool) ($options['repair'] ?? config('yunxiao.members.auto_repair', true));
        $knownPresent = $this->normalizeMemberIds((array) ($options['known_present'] ?? []));
        $added = [];
        $failures = [];
        $present = null;

        if ($expected !== []) {
            try {
                $present = array_merge(
                    array_map(
                        static fn (array $member): string => (string) ($member['id'] ?? ''),
                        $this->client->listMembers($connection, (string) $project->external_project_id),
                    ),
                    $knownPresent,
                );
            } catch (\Throwable) {
                $failures = $expected;
            }

            if ($present !== null) {
                $missing = array_values(array_diff($expected, $present));

                if ($missing !== [] && $repair) {
                    [$added, $failures] = $this->addMissingMembers(
                        $connection,
                        $project,
                        $missing,
                        $manager,
                        $options['role_id'] ?? null,
                    );
                } else {
                    $failures = $missing;
                }
            }
        }

        $metadata = (array) $project->metadata;

        if ($expected !== []) {
            $metadata['expected_assignments'] = $expected;
        }

        if ($manager !== null) {
            $metadata['project_manager'] = $manager;
        }

        if ($added !== []) {
            $metadata['managed_members'] = $this->mergeMemberIds((array) ($metadata['managed_members'] ?? []), $added);
        }

        $mappingPending = $project->isMappingPending();

        if ($mappingPending) {
            // 映射未补齐时保持 creation_failed：成员核验不能把 pending 项目改成 active。
            $project->status = YunxiaoProject::STATUS_CREATION_FAILED;

            if ($failures !== []) {
                $metadata['assignment_failures'] = $failures;
            } else {
                unset($metadata['assignment_failures']);
            }
        } elseif ($failures !== []) {
            $project->status = YunxiaoProject::STATUS_PARTIAL;
            $metadata['assignment_failures'] = $failures;
        } else {
            $project->status = YunxiaoProject::STATUS_ACTIVE;
            unset($metadata['assignment_failures']);
        }

        $project->metadata = $metadata;
        $project->save();

        if (! $mappingPending && $failures !== []) {
            Event::dispatch(new YunxiaoProjectPartial(
                $tenantId,
                (int) $project->project_id,
                (int) $project->yunxiao_project_id,
                (string) $project->external_project_id,
                'assignment_failed',
                $failures,
            ));
        } elseif (! $mappingPending) {
            Event::dispatch(new YunxiaoProjectCreated(
                $tenantId,
                (int) $project->project_id,
                (int) $project->yunxiao_project_id,
                (string) $project->external_project_id,
                (string) $project->custom_code,
                (string) $project->name,
            ));
        }

        return [
            'project' => $project->refresh(),
            'created' => $created,
            'mapping_status' => 'ok',
            'assignment_failures' => $failures,
            'added' => $added,
        ];
    }

    /**
     * 补齐缺失成员：负责人用管理员角色单独添加，其余用成员角色批量添加。
     *
     * @param  list<string>  $missing
     * @return array{0: list<string>, 1: list<string>} [已添加, 失败]
     */
    private function addMissingMembers(
        IntegrationConnection $connection,
        YunxiaoProject $project,
        array $missing,
        ?string $manager,
        ?string $roleId,
    ): array {
        $externalProjectId = (string) $project->external_project_id;
        $added = [];
        $failed = [];

        if ($manager !== null && in_array($manager, $missing, true)) {
            try {
                $this->client->addMembers($connection, $externalProjectId, [$manager], [
                    'role_id' => $this->managerRoleId($roleId),
                ]);
                $added[] = $manager;
            } catch (\Throwable) {
                $failed[] = $manager;
            }
        }

        $members = $manager === null ? $missing : array_values(array_diff($missing, [$manager]));

        if ($members !== []) {
            try {
                $this->client->addMembers($connection, $externalProjectId, $members, [
                    'role_id' => $this->memberRoleId($roleId),
                ]);
                $added = array_merge($added, $members);
            } catch (\Throwable) {
                $failed = array_merge($failed, $members);
            }
        }

        return [$added, $failed];
    }

    private function memberRoleId(?string $explicit): string
    {
        $explicit = trim((string) $explicit);

        return $explicit !== '' ? $explicit : (string) config('yunxiao.members.role_id', 'project.participant');
    }

    private function managerRoleId(?string $explicit): string
    {
        $explicit = trim((string) $explicit);

        return $explicit !== '' ? $explicit : (string) config('yunxiao.members.manager_role_id', 'project.admin');
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<string>
     */
    private function normalizeMemberIds(array $ids): array
    {
        $normalized = array_map(static fn ($id): string => trim((string) $id), array_values($ids));

        return array_values(array_unique(array_filter($normalized, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @param  list<string>  $additions
     * @return list<string>
     */
    private function mergeMemberIds(array $ids, array $additions): array
    {
        return $this->normalizeMemberIds(array_merge(
            $this->normalizeMemberIds($ids),
            $this->normalizeMemberIds($additions),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @param  list<string>  $removals
     * @return list<string>
     */
    private function withoutMemberIds(array $ids, array $removals): array
    {
        return $this->normalizeMemberIds(array_values(array_diff(
            $this->normalizeMemberIds($ids),
            $this->normalizeMemberIds($removals),
        )));
    }

    /**
     * customCode：显式值必须合规；缺失时由 tenant+宿主项目稳定生成（6 位大写字母）。
     */
    private function resolveCustomCode(int $tenantId, int $hostProjectId, ?string $provided): string
    {
        $pattern = (string) config('yunxiao.custom_code.pattern', '/^[A-Z]{4,6}$/');

        if ($provided !== null && trim($provided) !== '') {
            $code = trim($provided);

            if (preg_match($pattern, $code) !== 1) {
                throw new YunxiaoConfigurationException(
                    sprintf('customCode %s 不合规：必须是 4–6 位大写字母（平台不会静默换码）', $code),
                    YunxiaoConfigurationException::REASON_INVALID_CUSTOM_CODE,
                );
            }
        } else {
            $code = $this->generateCustomCode($tenantId, $hostProjectId);
        }

        $conflict = YunxiaoProject::query()
            ->where('tenant_id', $tenantId)
            ->where('custom_code', $code)
            ->where('project_id', '!=', $hostProjectId)
            ->exists();

        if ($conflict) {
            throw new YunxiaoConfigurationException(
                sprintf('customCode %s 已被本租户其他项目占用，请人工指定新编码', $code),
                YunxiaoConfigurationException::REASON_CUSTOM_CODE_CONFLICT,
            );
        }

        return $code;
    }

    /**
     * 稳定生成：tenant:project 的 base36 截断，数字位按 A–J 映射为字母，保证 6 位大写字母。
     */
    private function generateCustomCode(int $tenantId, int $hostProjectId): string
    {
        $seed = (int) sprintf('%u', crc32($tenantId . ':' . $hostProjectId));
        $base36 = strtoupper(base_convert((string) max(1, $seed), 10, 36));
        $letters = (string) preg_replace_callback(
            '/\d/',
            static fn (array $match): string => chr(65 + (int) $match[0]),
            $base36,
        );
        $length = (int) config('yunxiao.custom_code.generated_length', 6);
        $code = substr(str_pad($letters, $length, 'A', STR_PAD_LEFT), -$length);

        return $code !== '' ? $code : 'AAAAAA';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function expectedAssignments(array $input): array
    {
        $projectManager = trim((string) ($input['project_manager'] ?? ''));
        $members = array_values(array_filter(array_map(
            static fn ($member): string => trim((string) $member),
            (array) ($input['members'] ?? []),
        )));

        if ($projectManager !== '') {
            array_unshift($members, $projectManager);
        }

        return array_values(array_unique($members));
    }

    /**
     * 合并调用方输入与既有期望集合：缺省的键继承 metadata，避免部分更新意外清空成员。
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function requestedAssignments(YunxiaoProject $project, array $input): array
    {
        $metadata = (array) $project->metadata;
        $storedExpected = $this->normalizeMemberIds((array) ($metadata['expected_assignments'] ?? []));
        $storedManager = trim((string) ($metadata['project_manager'] ?? ''));

        $manager = array_key_exists('project_manager', $input)
            ? trim((string) ($input['project_manager'] ?? ''))
            : $storedManager;

        $members = array_key_exists('members', $input)
            ? array_values(array_map('strval', (array) ($input['members'] ?? [])))
            : array_values(array_diff($storedExpected, $storedManager === '' ? [] : [$storedManager]));

        return $this->expectedAssignments([
            'project_manager' => $manager,
            'members' => $members,
        ]);
    }

    private function projectMapping(int $tenantId, int $hostProjectId): ?ExternalMapping
    {
        foreach ($this->mappings->resolveByLocal($tenantId, 'project', $hostProjectId) as $mapping) {
            if ($mapping->source_system === YunxiaoConnectionService::PROVIDER && $mapping->source_type === 'project') {
                return $mapping;
            }
        }

        return null;
    }
}
