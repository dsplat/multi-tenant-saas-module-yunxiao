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
     * @param  array{project_id:int, name:string, project_type:string, custom_code?:string|null, scope?:string|null, description?:string|null, project_manager?:string|null, members?:list<string>, connection_id?:int|null}  $input
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>}
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
                    'expected_assignments' => $expected !== [] ? $expected : null,
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

            return $this->verifyAssignments($tenantId, $project, $connection, $expected);
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
     * 重试成员/PM 指派核验（不新增成员，无成员写 API 时降级为部分成功）。
     *
     * @return list<string> 仍缺失的成员 ID
     */
    public function retryAssignments(int $tenantId, int $yunxiaoProjectId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId): array {
            $project = $this->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $expected = array_values(array_map('strval', (array) ($project->metadata['expected_assignments'] ?? [])));
            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected);

            return $result['assignment_failures'];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>}
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
                ];
            }
        }

        if ($project->status === YunxiaoProject::STATUS_PARTIAL) {
            $connection = $this->connections->find($tenantId, (int) $project->connection_id);
            $expected = array_values(array_map('strval', (array) ($project->metadata['expected_assignments'] ?? [])));
            $result = $this->verifyAssignments($tenantId, $project, $connection, $expected);

            return [
                'project' => $result['project'],
                'created' => false,
                'mapping_status' => $result['mapping_status'],
                'assignment_failures' => $result['assignment_failures'],
            ];
        }

        return [
            'project' => $project,
            'created' => false,
            'mapping_status' => 'ok',
            'assignment_failures' => [],
        ];
    }

    /**
     * @param  list<string>  $expected
     * @return array{project:YunxiaoProject, created:bool, mapping_status:string, assignment_failures:list<string>}
     */
    private function verifyAssignments(
        int $tenantId,
        YunxiaoProject $project,
        IntegrationConnection $connection,
        array $expected,
    ): array {
        $failures = [];

        if ($expected !== []) {
            try {
                $members = $this->client->listMembers($connection, (string) $project->external_project_id);
                $present = array_map(
                    static fn (array $member): string => (string) ($member['id'] ?? ''),
                    $members,
                );
                $failures = array_values(array_filter(
                    $expected,
                    static fn (string $memberId): bool => ! in_array($memberId, $present, true),
                ));
            } catch (\Throwable) {
                $failures = $expected;
            }
        }

        $metadata = (array) $project->metadata;

        if ($failures !== []) {
            $project->status = YunxiaoProject::STATUS_PARTIAL;
            $metadata['assignment_failures'] = $failures;
            $project->metadata = $metadata;
            $project->save();

            Event::dispatch(new YunxiaoProjectPartial(
                $tenantId,
                (int) $project->project_id,
                (int) $project->yunxiao_project_id,
                (string) $project->external_project_id,
                'assignment_failed',
                $failures,
            ));
        } else {
            $project->status = YunxiaoProject::STATUS_ACTIVE;
            unset($metadata['assignment_failures']);
            $project->metadata = $metadata;
            $project->save();

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
            'created' => true,
            'mapping_status' => 'ok',
            'assignment_failures' => $failures,
        ];
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
