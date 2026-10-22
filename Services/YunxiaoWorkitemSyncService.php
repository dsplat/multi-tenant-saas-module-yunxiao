<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Models\IntegrationSyncRun;
use MultiTenantSaas\Modules\Integration\Services\ExternalMappingService;
use MultiTenantSaas\Modules\Integration\Services\IntegrationSyncCursorService;
use MultiTenantSaas\Modules\Integration\Services\IntegrationSyncRunService;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoWorkitemSynced;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitem;

/**
 * 工作项投影同步（只读投影，不提供编辑接口，也不写实际工时）
 *
 * - 增量：按项目 + updatedSince，游标落 IntegrationSyncCursorService（resource=workitems）；
 * - 一次同步一条 IntegrationSyncRun；
 * - 分页 + 429 限流退避；某页失败不回滚已成功页，游标只推进到成功页的最大更新时间；
 * - upsert 以 serialNumber 为对账键；未知状态原样保存并标 metadata.unmapped=true，不猜测；
 * - 详情实时拉取失败/超时回退缓存，并标 metadata.stale=true。
 */
class YunxiaoWorkitemSyncService
{
    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
        private readonly YunxiaoProjectService $projects,
        private readonly YunxiaoTemplateMapService $templates,
        private readonly ExternalMappingService $mappings,
        private readonly IntegrationSyncCursorService $cursors,
        private readonly IntegrationSyncRunService $runs,
    ) {}

    /**
     * @param  array{project_id?:int|string|null, yunxiao_project_id?:int|string|null, connection_id?:int|string|null, dry_run?:bool, trigger?:string}  $options
     * @return array<string, mixed>
     */
    public function sync(int $tenantId, array $options = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $options): array {
            $dryRun = (bool) ($options['dry_run'] ?? false);
            $projects = $this->syncableProjects($tenantId, $options);
            $connectionId = isset($options['connection_id']) && $options['connection_id'] !== null
                ? (int) $options['connection_id']
                : (int) $projects[0]->connection_id;
            $connection = $this->connections->find($tenantId, $connectionId);
            $resource = (string) config('yunxiao.sync.resource', 'workitems');

            $stats = [
                'tenant_id' => $tenantId,
                'connection_id' => $connectionId,
                'resource' => $resource,
                'dry_run' => $dryRun,
                'projects' => count($projects),
                'pages' => 0,
                'fetched' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'partial' => false,
                'errors' => [],
                'cursor' => null,
            ];

            if ($dryRun) {
                foreach ($projects as $project) {
                    $this->syncProject($tenantId, $connection, $project, $resource, $stats, true);
                }

                return $stats;
            }

            $run = $this->runs->start(
                $tenantId,
                $connectionId,
                $resource,
                IntegrationSyncRun::DIRECTION_INBOUND,
                (string) ($options['trigger'] ?? IntegrationSyncRun::TRIGGER_MANUAL),
            );

            try {
                foreach ($projects as $project) {
                    $this->syncProject($tenantId, $connection, $project, $resource, $stats, false);
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = $this->safeError($e);
                $this->runs->fail($tenantId, (int) $run->run_id, $this->safeError($e), $stats);

                throw $e;
            }

            if ($stats['pages'] === 0 && $stats['errors'] !== []) {
                $this->runs->fail($tenantId, (int) $run->run_id, (string) $stats['errors'][0], $stats);
            } else {
                $this->runs->finish($tenantId, (int) $run->run_id, $stats);
            }

            return $stats;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null, ?int $page = null): LengthAwarePaginator
    {
        $query = YunxiaoWorkitem::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        return $this->applyFilters($query, $filters)
            ->orderByDesc('fetched_at')
            ->orderByDesc('yunxiao_workitem_id')
            ->paginate($perPage ?? (int) config('yunxiao.per_page', 20), ['*'], 'page', $page);
    }

    public function find(int $tenantId, int $workitemId): YunxiaoWorkitem
    {
        $query = YunxiaoWorkitem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($workitemId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        $item = $query->first();

        if (! $item instanceof YunxiaoWorkitem) {
            throw new NotFoundException('云效工作项不存在');
        }

        return $item;
    }

    /**
     * 详情：优先实时拉取；失败/超时回退缓存并标记 stale（不把异常当数据猜测）。
     *
     * @return array{workitem:YunxiaoWorkitem, stale:bool, error:string|null}
     */
    public function detail(int $tenantId, int $workitemId, bool $refresh = true): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $workitemId, $refresh): array {
            $cached = $this->find($tenantId, $workitemId);

            if (! $refresh) {
                return ['workitem' => $cached, 'stale' => $this->isStale($cached), 'error' => null];
            }

            $connection = $this->connections->find($tenantId, (int) $cached->connection_id);

            try {
                $remote = $this->client->getWorkitem($connection, (string) $cached->external_workitem_id);
                $updated = $this->refreshFromRemote($tenantId, $cached, $remote);

                return ['workitem' => $updated, 'stale' => false, 'error' => null];
            } catch (\Throwable $e) {
                $metadata = (array) $cached->metadata;
                $metadata['stale'] = true;
                $metadata['stale_at'] = now()->toIso8601String();
                $metadata['stale_reason'] = 'detail_fetch_failed';
                $cached->metadata = $metadata;
                $cached->save();

                return [
                    'workitem' => $cached->refresh(),
                    'stale' => true,
                    'error' => $this->safeError($e),
                ];
            }
        });
    }

    /**
     * 进度汇总（AI L1）：仅聚合状态/数量，不输出成员明细。
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, array $filters = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $filters): array {
            $query = YunxiaoWorkitem::query()->where('tenant_id', $tenantId);

            if ($scope = DataScopeContext::ambient()) {
                $scope->applyTo($query);
            }

            $query = $this->applyFilters($query, $filters);

            $total = (clone $query)->count();
            $unmapped = (clone $query)->get()->filter(
                static fn (YunxiaoWorkitem $item): bool => $item->isUnmapped(),
            )->count();

            $statuses = (clone $query)
                ->selectRaw('status_id, status_name, COUNT(*) AS aggregate')
                ->groupBy('status_id', 'status_name')
                ->orderByDesc('aggregate')
                ->orderBy('status_id')
                ->get()
                ->map(static fn ($row): array => [
                    'status_id' => $row->status_id,
                    'status_name' => $row->status_name,
                    'count' => (int) $row->aggregate,
                ])
                ->all();

            $lastSyncedAt = (clone $query)->max('fetched_at');

            return [
                'total' => $total,
                'unmapped' => $unmapped,
                'statuses' => $statuses,
                'last_synced_at' => $lastSyncedAt,
            ];
        });
    }

    /**
     * @return list<YunxiaoProject>
     */
    private function syncableProjects(int $tenantId, array $options): array
    {
        if (isset($options['yunxiao_project_id']) && $options['yunxiao_project_id'] !== null) {
            return [$this->projects->find($tenantId, (int) $options['yunxiao_project_id'])];
        }

        if (isset($options['project_id']) && $options['project_id'] !== null) {
            $project = YunxiaoProject::query()
                ->where('tenant_id', $tenantId)
                ->where('project_id', (int) $options['project_id'])
                ->first();

            if (! $project instanceof YunxiaoProject) {
                throw new YunxiaoConfigurationException(
                    '云效项目投影不存在（请先创建项目）',
                    YunxiaoConfigurationException::REASON_PROJECT_NOT_FOUND,
                );
            }

            return [$project];
        }

        $projects = YunxiaoProject::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [YunxiaoProject::STATUS_ACTIVE, YunxiaoProject::STATUS_PARTIAL])
            ->orderBy('yunxiao_project_id')
            ->get()
            ->all();

        if ($projects === []) {
            throw new YunxiaoConfigurationException(
                '没有可同步的云效项目（先创建项目或检查项目状态）',
                YunxiaoConfigurationException::REASON_PROJECT_NOT_FOUND,
            );
        }

        return $projects;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function syncProject(
        int $tenantId,
        IntegrationConnection $connection,
        YunxiaoProject $project,
        string $resource,
        array &$stats,
        bool $dryRun,
    ): void {
        $cursor = $this->cursors->get($tenantId, (int) $connection->connection_id, $resource);
        $meta = (array) ($cursor?->cursor_meta ?? []);
        $perProject = (array) ($meta['projects'] ?? []);
        $projectKey = (string) $project->external_project_id;
        $updatedSince = isset($perProject[$projectKey]) ? (string) $perProject[$projectKey] : null;

        $pageSize = (int) config('yunxiao.api.page_size', 100);
        $maxPages = (int) config('yunxiao.api.max_pages', 100);
        $maxRetries = (int) config('yunxiao.sync.max_rate_limit_retries', 2);
        $knownStatuses = $this->templates->knownStatusIdsForProject($tenantId, $project);
        $maxUpdated = null;
        $page = 1;

        while ($page <= $maxPages) {
            try {
                $result = $this->fetchPage($connection, $project, $page, $pageSize, $updatedSince, $maxRetries);
            } catch (\Throwable $e) {
                $stats['partial'] = true;
                $stats['errors'][] = sprintf('项目 %s 第 %d 页：%s', $project->external_project_id, $page, $this->safeError($e));

                break;
            }

            $stats['pages']++;
            $items = (array) ($result['items'] ?? []);

            if ($items === []) {
                break;
            }

            foreach ($items as $payload) {
                $stats['fetched']++;

                try {
                    $outcome = $this->projectItem($tenantId, $connection, $project, (array) $payload, $knownStatuses, $dryRun);
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $stats['errors'][] = sprintf('项目 %s 工作项：%s', $project->external_project_id, $this->safeError($e));

                    continue;
                }

                if ($outcome['skipped']) {
                    $stats['skipped']++;

                    continue;
                }

                $stats[$outcome['created'] ? 'created' : 'updated']++;

                if ($outcome['updated_at'] !== null
                    && ($maxUpdated === null || $outcome['updated_at'] > $maxUpdated)) {
                    $maxUpdated = $outcome['updated_at'];
                }
            }

            if (! (bool) ($result['has_more'] ?? false)) {
                break;
            }

            $page++;
        }

        if ($maxUpdated !== null) {
            $perProject[$projectKey] = $maxUpdated;
        }

        if (! $dryRun) {
            $project->last_synced_at = now();
            $project->save();

            if ($maxUpdated !== null) {
                $meta['projects'] = $perProject;
                $current = (string) ($cursor?->cursor_value ?? '');
                $cursorValue = $current === '' || $maxUpdated > $current ? $maxUpdated : $current;
                $this->cursors->advance(
                    $tenantId,
                    (int) $connection->connection_id,
                    $resource,
                    $cursorValue,
                    $meta,
                );
                $stats['cursor'] = $cursorValue;
            }
        }
    }

    /**
     * @return array{items:list<array<string,mixed>>,has_more:bool,total:int|null}
     */
    private function fetchPage(
        IntegrationConnection $connection,
        YunxiaoProject $project,
        int $page,
        int $pageSize,
        ?string $updatedSince,
        int $maxRetries,
    ): array {
        $attempt = 0;

        while (true) {
            try {
                return $this->client->listWorkitems($connection, (string) $project->external_project_id, array_filter([
                    'page' => $page,
                    'per_page' => $pageSize,
                    'updated_since' => $updatedSince,
                ], static fn ($value): bool => $value !== null && $value !== ''));
            } catch (YunxiaoApiException $e) {
                if ($e->reason === YunxiaoApiException::REASON_RATE_LIMITED && $attempt < $maxRetries) {
                    $attempt++;
                    $this->backoff($attempt);

                    continue;
                }

                throw $e;
            }
        }
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('yunxiao.sync.rate_limit_backoff_ms', 1000));

        if ($base > 0) {
            usleep($base * $attempt * 1000);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $knownStatuses
     * @return array{created:bool, skipped:bool, updated_at:string|null}
     */
    private function projectItem(
        int $tenantId,
        IntegrationConnection $connection,
        YunxiaoProject $project,
        array $payload,
        array $knownStatuses,
        bool $dryRun,
    ): array {
        $externalId = trim((string) ($payload['external_workitem_id'] ?? $payload['id'] ?? ''));
        $serialNumber = trim((string) ($payload['serial_number'] ?? $payload['serialNumber'] ?? ''));

        if ($externalId === '') {
            throw new YunxiaoException('云效工作项缺少 ID');
        }

        $statusId = $this->stringOrNull($payload['status_id'] ?? null);
        $statusName = $this->stringOrNull($payload['status_name'] ?? null);
        $unmapped = $statusId !== null
            && ($knownStatuses === [] || ! in_array($statusId, $knownStatuses, true));

        $updatedAt = $this->timestamp($payload['gmt_modified'] ?? null);
        $createdAt = $this->timestamp($payload['gmt_created'] ?? null);

        if ($dryRun) {
            $existing = YunxiaoWorkitem::query()
                ->where('tenant_id', $tenantId)
                ->where('external_workitem_id', $externalId)
                ->exists();

            return [
                'created' => ! $existing,
                'skipped' => false,
                'updated_at' => $updatedAt?->toIso8601String(),
            ];
        }

        $existing = YunxiaoWorkitem::query()
            ->where('tenant_id', $tenantId)
            ->where('external_workitem_id', $externalId)
            ->first();

        $metadata = array_merge((array) ($existing?->metadata ?? []), [
            'unmapped' => $unmapped,
            'stale' => false,
            'synced_at' => now()->toIso8601String(),
        ]);
        unset($metadata['stale_at'], $metadata['stale_reason']);

        $item = YunxiaoWorkitem::updateOrCreate(
            ['tenant_id' => $tenantId, 'external_workitem_id' => $externalId],
            [
                'connection_id' => (int) $connection->connection_id,
                'yunxiao_project_id' => (int) $project->yunxiao_project_id,
                'serial_number' => $serialNumber !== '' ? $serialNumber : ($existing?->serial_number ?? ''),
                'workitem_type_id' => $this->stringOrNull($payload['workitem_type_id'] ?? null),
                'subject' => (string) ($payload['subject'] ?? ''),
                'status_id' => $statusId,
                'status_name' => $statusName,
                'assigned_to' => $this->stringOrNull($payload['assigned_to'] ?? null),
                'gmt_created' => $createdAt,
                'gmt_modified' => $updatedAt,
                'fetched_at' => now(),
                'metadata' => $metadata,
            ],
        );

        if ($serialNumber !== '') {
            try {
                $this->mappings->upsert(
                    $tenantId,
                    YunxiaoConnectionService::PROVIDER,
                    'workitem',
                    $serialNumber,
                    'workitem',
                    (int) $item->yunxiao_workitem_id,
                    [
                        'external_workitem_id' => $externalId,
                        'subject' => (string) ($payload['subject'] ?? ''),
                    ],
                );
            } catch (\Throwable) {
                // 对账映射失败不阻断投影；写回时仍可按 serial_number 回落到投影表。
            }
        }

        Event::dispatch(new YunxiaoWorkitemSynced(
            $tenantId,
            (int) $project->yunxiao_project_id,
            (int) $item->yunxiao_workitem_id,
            $serialNumber !== '' ? $serialNumber : null,
            $statusId,
            $unmapped,
        ));

        return [
            'created' => (bool) $item->wasRecentlyCreated,
            'skipped' => false,
            'updated_at' => $updatedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function refreshFromRemote(int $tenantId, YunxiaoWorkitem $cached, array $remote): YunxiaoWorkitem
    {
        $serialNumber = trim((string) ($remote['serial_number'] ?? $cached->serial_number));
        $statusId = $this->stringOrNull($remote['status_id'] ?? null) ?? $cached->status_id;
        $project = YunxiaoProject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($cached->yunxiao_project_id)
            ->first();
        $knownStatuses = $project instanceof YunxiaoProject
            ? $this->templates->knownStatusIdsForProject($tenantId, $project)
            : [];
        $unmapped = $statusId !== null
            && ($knownStatuses === [] || ! in_array($statusId, $knownStatuses, true));

        $metadata = (array) $cached->metadata;
        $metadata['unmapped'] = $unmapped;
        $metadata['stale'] = false;
        $metadata['synced_at'] = now()->toIso8601String();
        unset($metadata['stale_at'], $metadata['stale_reason']);

        $cached->fill([
            'serial_number' => $serialNumber !== '' ? $serialNumber : $cached->serial_number,
            'workitem_type_id' => $this->stringOrNull($remote['workitem_type_id'] ?? null) ?? $cached->workitem_type_id,
            'subject' => (string) ($remote['subject'] ?? $cached->subject),
            'status_id' => $statusId,
            'status_name' => $this->stringOrNull($remote['status_name'] ?? null) ?? $cached->status_name,
            'assigned_to' => $this->stringOrNull($remote['assigned_to'] ?? null) ?? $cached->assigned_to,
            'gmt_created' => $this->timestamp($remote['gmt_created'] ?? null) ?? $cached->gmt_created,
            'gmt_modified' => $this->timestamp($remote['gmt_modified'] ?? null) ?? $cached->gmt_modified,
            'fetched_at' => now(),
            'metadata' => $metadata,
        ]);
        $cached->save();

        if ($serialNumber !== '') {
            try {
                $this->mappings->upsert(
                    $tenantId,
                    YunxiaoConnectionService::PROVIDER,
                    'workitem',
                    $serialNumber,
                    'workitem',
                    (int) $cached->yunxiao_workitem_id,
                    ['external_workitem_id' => (string) $cached->external_workitem_id],
                );
            } catch (\Throwable) {
                // 映射失败不影响详情返回
            }
        }

        return $cached->refresh();
    }

    private function isStale(YunxiaoWorkitem $item): bool
    {
        if ((bool) ($item->metadata['stale'] ?? false)) {
            return true;
        }

        $fetchedAt = $item->fetched_at;

        return $fetchedAt !== null
            && $fetchedAt->lessThan(now()->subMinutes((int) config('yunxiao.sync.stale_minutes', 15)));
    }

    /**
     * @param  Builder<YunxiaoWorkitem>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<YunxiaoWorkitem>
     */
    private function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['project_id'] ?? null, fn ($builder, $value) => $builder->whereHas(
                'project',
                fn ($project) => $project->where('project_id', (int) $value),
            ))
            ->when($filters['yunxiao_project_id'] ?? null, fn ($builder, $value) => $builder->where('yunxiao_project_id', (int) $value))
            ->when($filters['serial_number'] ?? null, fn ($builder, $value) => $builder->where('serial_number', (string) $value))
            ->when($filters['external_workitem_id'] ?? null, fn ($builder, $value) => $builder->where('external_workitem_id', (string) $value))
            ->when($filters['status_id'] ?? null, fn ($builder, $value) => $builder->where('status_id', (string) $value))
            ->when(array_key_exists('unmapped', $filters) && $filters['unmapped'] !== null, function ($builder) use ($filters): void {
                if ((bool) $filters['unmapped']) {
                    $builder->where('metadata->unmapped', true);
                } else {
                    $builder->where(function ($inner): void {
                        $inner->whereNull('metadata->unmapped')->orWhere('metadata->unmapped', false);
                    });
                }
            })
            ->when($filters['keyword'] ?? null, fn ($builder, $value) => $builder->where(function ($inner) use ($value): void {
                $inner->where('serial_number', 'like', '%' . $value . '%')
                    ->orWhere('subject', 'like', '%' . $value . '%');
            }))
            ->when($filters['updated_since'] ?? null, fn ($builder, $value) => $builder->where('gmt_modified', '>=', $value));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $value['id'] ?? $value['identifier'] ?? null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeError(\Throwable $e): string
    {
        if ($e instanceof YunxiaoException) {
            return mb_substr($e->getMessage(), 0, 500);
        }

        return '云效工作项同步失败';
    }
}
