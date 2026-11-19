<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitem;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitemImport;

/**
 * 需求导入云效（工作项创建，issue #1）
 *
 * 云效 CreateWorkitem 没有业务唯一键、响应只回 `{id}`，所以「本地已发起、远端是否已创建」
 * 必须在调用前留痕。本服务以 `yunxiao_workitem_imports` 为账本：
 * - 同键同 payload：created 直接重放，failed 同键重试，pending 显式 uncertain；
 * - 同键异 payload：冲突，不覆盖原请求；
 * - 成功后立即 upsert `yunxiao_workitems` 投影，不等下一轮同步；
 * - `status_id` 在创建后补写；补写失败只记 warning 与 status_pending，重放时补做；
 * - 单条失败不中断整批，返回逐条明细。
 */
class YunxiaoWorkitemImportService
{
    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
        private readonly YunxiaoProjectService $projects,
        private readonly YunxiaoTemplateMapService $templates,
    ) {}

    /**
     * @param  array{items?:list<array<string,mixed>>, assigned_to?:string|null, workitem_type_id?:string|null, workitem_type?:string|null, priority?:string|null, status_id?:string|null, dry_run?:bool, retry_pending?:bool}  $input
     * @return array<string, mixed>
     */
    public function import(int $tenantId, int $yunxiaoProjectId, array $input): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $input): array {
            $project = $this->projects->find($tenantId, $yunxiaoProjectId);
            $connection = $this->connections->activeConnection($tenantId, (int) $project->connection_id);
            $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));
            $maxItems = max(1, (int) config('yunxiao.import.max_items', 100));

            if ($items === [] || count($items) > $maxItems) {
                throw new YunxiaoConfigurationException(
                    $items === []
                        ? '导入条目不能为空'
                        : sprintf('单次最多导入 %d 条需求', $maxItems),
                );
            }

            $dryRun = (bool) ($input['dry_run'] ?? false);
            $retryPending = (bool) ($input['retry_pending'] ?? false);
            $defaults = [
                'assigned_to' => trim((string) ($input['assigned_to'] ?? '')),
                'workitem_type_id' => trim((string) ($input['workitem_type_id'] ?? '')),
                'workitem_type' => trim((string) ($input['workitem_type'] ?? '')),
                'priority' => trim((string) ($input['priority'] ?? '')),
                'status_id' => trim((string) ($input['status_id'] ?? '')),
            ];
            $typeMap = $this->templates->workitemTypeMapForProject($tenantId, $project);
            $knownStatuses = $this->templates->knownStatusIdsForProject($tenantId, $project);

            $stats = [
                'yunxiao_project_id' => (int) $project->yunxiao_project_id,
                'external_project_id' => (string) $project->external_project_id,
                'dry_run' => $dryRun,
                'requested' => count($items),
                'created' => 0,
                'replayed' => 0,
                'failed' => 0,
                'uncertain' => 0,
                'dry_run_items' => 0,
                'partial' => false,
                'items' => [],
            ];

            foreach ($items as $index => $item) {
                $result = $this->importItem(
                    $tenantId,
                    $connection,
                    $project,
                    $item,
                    $defaults,
                    $typeMap,
                    $knownStatuses,
                    $index,
                    $dryRun,
                    $retryPending,
                );

                if ($result['status'] === 'dry_run') {
                    $stats['dry_run_items']++;
                } elseif (in_array($result['status'], ['created', 'replayed', 'failed', 'uncertain'], true)) {
                    $stats[$result['status']]++;
                }

                $stats['items'][] = $result;
            }

            $stats['partial'] = $stats['failed'] > 0 || $stats['uncertain'] > 0;

            return $stats;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $defaults
     * @param  array<string, string>  $typeMap
     * @param  list<string>  $knownStatuses
     * @return array<string, mixed>
     */
    private function importItem(
        int $tenantId,
        IntegrationConnection $connection,
        YunxiaoProject $project,
        array $item,
        array $defaults,
        array $typeMap,
        array $knownStatuses,
        int $index,
        bool $dryRun,
        bool $retryPending,
    ): array {
        $title = trim((string) ($item['title'] ?? $item['subject'] ?? ''));
        $description = $this->stringOrNull($item['description'] ?? null);
        $assignedTo = trim((string) ($item['assigned_to'] ?? $item['assignedTo'] ?? $defaults['assigned_to']));
        $workitemTypeId = trim((string) ($item['workitem_type_id'] ?? $item['workitemTypeId'] ?? $defaults['workitem_type_id']));
        $workitemType = trim((string) ($item['workitem_type'] ?? $defaults['workitem_type']));
        $priorityRaw = trim((string) ($item['priority'] ?? $defaults['priority']));
        $statusId = trim((string) ($item['status_id'] ?? $item['statusId'] ?? $defaults['status_id']));
        $parentId = $this->stringOrNull($item['parent_id'] ?? $item['parentId'] ?? null);
        $customFieldValues = $this->scalarMap((array) ($item['custom_field_values'] ?? $item['customFieldValues'] ?? []));

        if ($title === '') {
            return $this->itemFailure($index, 'title_required', '工作项标题不能为空');
        }

        if ($assignedTo === '') {
            return $this->itemFailure($index, 'assigned_to_required', '云效创建工作项必须指定 assigned_to（云效 userId）');
        }

        if ($workitemTypeId === '' && $workitemType !== '') {
            $workitemTypeId = (string) ($typeMap[$workitemType] ?? '');
        }

        if ($workitemTypeId === '') {
            return $this->itemFailure(
                $index,
                'workitem_type_required',
                '缺少 workitem_type_id；也可传 workitem_type 并在模板映射中配置宿主类型 → 云效工作项类型 ID',
            );
        }

        $priority = $this->resolvePriority($priorityRaw);

        if ($priority !== null) {
            $customFieldValues['priority'] = $priority;
        }

        $payload = [
            'title' => $title,
            'description' => $description,
            'assigned_to' => $assignedTo,
            'workitem_type_id' => $workitemTypeId,
            'status_id' => $statusId !== '' ? $statusId : null,
            'priority' => $priority,
            'parent_id' => $parentId,
            'custom_field_values' => $customFieldValues,
        ];
        $payloadHash = $this->payloadHash($payload);
        $idempotencyKey = trim((string) ($item['idempotency_key'] ?? ''));

        if ($idempotencyKey === '') {
            // 没给业务键时用归一化 payload 派生，保证同内容重试不重复建。
            $idempotencyKey = 'auto:' . $payloadHash;
        }

        if (mb_strlen($idempotencyKey) > 191) {
            return $this->itemFailure($index, 'idempotency_key_invalid', 'idempotency_key 最长 191 个字符');
        }

        $existing = YunxiaoWorkitemImport::query()
            ->where('tenant_id', $tenantId)
            ->where('yunxiao_project_id', (int) $project->yunxiao_project_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof YunxiaoWorkitemImport && ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            return $this->itemFailure(
                $index,
                'idempotency_key_conflict',
                '同一 idempotency_key 对应不同 payload；请为修正后的需求使用新的业务键',
                $idempotencyKey,
            );
        }

        if ($existing instanceof YunxiaoWorkitemImport && $existing->isCreated()) {
            if ($dryRun) {
                return $this->itemResult($index, 'replayed', $idempotencyKey, $existing, ['warning' => null]);
            }

            return $this->replayCreated($index, $connection, $project, $existing, $statusId, $knownStatuses);
        }

        if ($existing instanceof YunxiaoWorkitemImport && $existing->isPending() && ! $retryPending) {
            return $this->itemResult($index, 'uncertain', $idempotencyKey, $existing, [
                'reason' => 'previous_attempt_pending',
                'error' => '上一次导入在远端调用后未回填（可能已创建）；为避免重复，未自动重试；如确认需要重试请传 retry_pending=true',
            ]);
        }

        if ($dryRun) {
            return [
                'index' => $index,
                'status' => 'dry_run',
                'idempotency_key' => $idempotencyKey,
                'external_workitem_id' => null,
                'serial_number' => null,
                'workitem_type_id' => $workitemTypeId,
                'assigned_to' => $assignedTo,
                'status_applied' => $statusId === '',
                'warning' => null,
                'reason' => $existing instanceof YunxiaoWorkitemImport && $existing->status === YunxiaoWorkitemImport::STATUS_FAILED
                    ? 'would_retry'
                    : null,
                'error' => null,
                'attempts' => (int) ($existing?->attempts ?? 0),
            ];
        }

        if ($existing instanceof YunxiaoWorkitemImport) {
            // failed / 显式 retry_pending 的 pending：同键重试，先回 pending 留痕。
            $existing->fill([
                'status' => YunxiaoWorkitemImport::STATUS_PENDING,
                'connection_id' => (int) $connection->connection_id,
                'subject' => $title,
                'workitem_type_id' => $workitemTypeId,
                'assigned_to' => $assignedTo,
                'last_error' => null,
            ]);
            $existing->attempts = (int) $existing->attempts + 1;
            $existing->save();
            $ledger = $existing;
        } else {
            try {
                $ledger = YunxiaoWorkitemImport::create([
                    'tenant_id' => $tenantId,
                    'connection_id' => (int) $connection->connection_id,
                    'yunxiao_project_id' => (int) $project->yunxiao_project_id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'status' => YunxiaoWorkitemImport::STATUS_PENDING,
                    'subject' => $title,
                    'workitem_type_id' => $workitemTypeId,
                    'assigned_to' => $assignedTo,
                    'attempts' => 1,
                ]);
            } catch (QueryException $e) {
                // 并发同键：唯一索引让其中一个失败；读回已存在行，绝不重复调远端。
                $existing = YunxiaoWorkitemImport::query()
                    ->where('tenant_id', $tenantId)
                    ->where('yunxiao_project_id', (int) $project->yunxiao_project_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if (! $existing instanceof YunxiaoWorkitemImport) {
                    throw $e;
                }

                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    return $this->itemFailure(
                        $index,
                        'idempotency_key_conflict',
                        '同一 idempotency_key 对应不同 payload；请为修正后的需求使用新的业务键',
                        $idempotencyKey,
                    );
                }

                if ($existing->isCreated()) {
                    return $this->replayCreated($index, $connection, $project, $existing, $statusId, $knownStatuses);
                }

                return $this->itemResult($index, 'uncertain', $idempotencyKey, $existing, [
                    'reason' => 'concurrent_attempt',
                    'error' => '同一业务键的另一次导入仍在进行；为避免重复创建，本次未调用云效',
                ]);
            }
        }

        try {
            $remote = $this->client->createWorkitem($connection, (string) $project->external_project_id, $payload);
        } catch (\Throwable $e) {
            $ledger->fill([
                'status' => YunxiaoWorkitemImport::STATUS_FAILED,
                'last_error' => $this->safeError($e),
            ])->save();

            return $this->itemResult($index, 'failed', $idempotencyKey, $ledger, [
                'reason' => $e instanceof YunxiaoApiException ? $e->reason : 'create_failed',
                'error' => $this->safeError($e),
                'workitem_type_id' => $workitemTypeId,
                'assigned_to' => $assignedTo,
            ]);
        }

        $externalId = trim((string) ($remote['external_workitem_id'] ?? ''));

        if ($externalId === '') {
            $ledger->fill([
                'status' => YunxiaoWorkitemImport::STATUS_FAILED,
                'last_error' => '云效创建工作项返回缺少工作项 ID',
            ])->save();

            return $this->itemResult($index, 'failed', $idempotencyKey, $ledger, [
                'reason' => 'missing_external_id',
                'error' => '云效创建工作项返回缺少工作项 ID',
                'workitem_type_id' => $workitemTypeId,
                'assigned_to' => $assignedTo,
            ]);
        }

        [$statusApplied, $statusWarning, $remote] = $this->applyStatus(
            $connection,
            $externalId,
            $statusId,
            $remote,
        );

        $serialNumber = $this->stringOrNull($remote['serial_number'] ?? null);
        $metadata = array_merge((array) ($ledger->metadata ?? []), [
            'status_applied' => $statusApplied,
            'status_pending' => ! $statusApplied,
            'imported_at' => now()->toIso8601String(),
        ]);

        $ledger->fill([
            'status' => YunxiaoWorkitemImport::STATUS_CREATED,
            'external_workitem_id' => $externalId,
            'serial_number' => $serialNumber,
            'workitem_type_id' => $workitemTypeId,
            'assigned_to' => $assignedTo,
            'last_error' => null,
            'metadata' => $metadata,
        ]);

        try {
            $ledger->save();
        } catch (\Throwable) {
            // 远端已创建：即使账本回填失败也要把 external id 交给调用方，避免「建了但没记录」。
            return $this->itemResult($index, 'created', $idempotencyKey, $ledger, [
                'status' => 'created',
                'external_workitem_id' => $externalId,
                'status_applied' => $statusApplied,
                'warning' => 'ledger_write_failed',
                'workitem_type_id' => $workitemTypeId,
                'assigned_to' => $assignedTo,
            ]);
        }

        $projectionWritten = $this->project(
            $tenantId,
            $connection,
            $project,
            $externalId,
            $idempotencyKey,
            $payload,
            $remote,
            $statusId,
            $knownStatuses,
        );

        return $this->itemResult($index, 'created', $idempotencyKey, $ledger, [
            'status' => 'created',
            'status_applied' => $statusApplied,
            'warning' => $statusWarning ?? ($projectionWritten ? null : 'projection_failed'),
            'workitem_type_id' => $workitemTypeId,
            'assigned_to' => $assignedTo,
        ]);
    }

    /**
     * created 行重放：不再建远端；若上次 status 补写失败则补做。
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function replayCreated(
        int $index,
        IntegrationConnection $connection,
        YunxiaoProject $project,
        YunxiaoWorkitemImport $ledger,
        string $statusId,
        array $knownStatuses,
    ): array {
        $metadata = (array) ($ledger->metadata ?? []);
        $warning = null;
        $statusApplied = (bool) ($metadata['status_applied'] ?? true);

        if ($statusId !== '' && (bool) ($metadata['status_pending'] ?? false)) {
            $remote = ['external_workitem_id' => (string) $ledger->external_workitem_id];

            try {
                $updated = $this->client->updateWorkitem($connection, (string) $ledger->external_workitem_id, ['status_id' => $statusId]);
                $remote = array_merge($remote, array_filter($updated, static fn ($value): bool => $value !== null && $value !== ''));
                $statusApplied = true;
                $metadata['status_applied'] = true;
                $metadata['status_pending'] = false;
                $metadata['status_applied_at'] = now()->toIso8601String();
                $ledger->metadata = $metadata;
                $ledger->save();
                $this->project(
                    $ledger->tenant_id,
                    $connection,
                    $project,
                    (string) $ledger->external_workitem_id,
                    (string) $ledger->idempotency_key,
                    [
                        'title' => (string) $ledger->subject,
                        'workitem_type_id' => $ledger->workitem_type_id,
                        'assigned_to' => $ledger->assigned_to,
                    ],
                    $remote,
                    $statusId,
                    $knownStatuses,
                );
            } catch (\Throwable $e) {
                $warning = 'status_update_failed';
                $statusApplied = false;
                $metadata['status_pending'] = true;
                $metadata['status_error'] = $this->safeError($e);
                $ledger->metadata = $metadata;
                $ledger->save();
            }
        }

        return $this->itemResult($index, 'replayed', (string) $ledger->idempotency_key, $ledger, [
            'status_applied' => $statusApplied,
            'warning' => $warning,
        ]);
    }

    /**
     * 创建后补写状态：云效创建 body 不含 status，失败不回滚已创建项。
     *
     * @param  array<string, mixed>  $remote
     * @return array{0:bool,1:string|null,2:array<string,mixed>}
     */
    private function applyStatus(
        IntegrationConnection $connection,
        string $externalWorkitemId,
        string $statusId,
        array $remote,
    ): array {
        if ($statusId === '') {
            return [true, null, $remote];
        }

        try {
            $updated = $this->client->updateWorkitem($connection, $externalWorkitemId, ['status_id' => $statusId]);

            return [true, null, array_merge($remote, array_filter($updated, static fn ($value): bool => $value !== null && $value !== ''))];
        } catch (\Throwable) {
            return [false, 'status_update_failed', $remote];
        }
    }

    /**
     * 写入本地工作项投影（external_workitem_id 唯一键），不等下一轮同步。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $remote
     * @param  list<string>  $knownStatuses
     */
    private function project(
        int $tenantId,
        IntegrationConnection $connection,
        YunxiaoProject $project,
        string $externalId,
        string $idempotencyKey,
        array $payload,
        array $remote,
        string $statusId,
        array $knownStatuses,
    ): bool {
        try {
            $existing = YunxiaoWorkitem::query()
                ->where('tenant_id', $tenantId)
                ->where('external_workitem_id', $externalId)
                ->first();

            $resolvedStatusId = $this->stringOrNull($remote['status_id'] ?? null) ?? ($statusId !== '' ? $statusId : null);
            $unmapped = $resolvedStatusId !== null
                && ($knownStatuses === [] || ! in_array($resolvedStatusId, $knownStatuses, true));

            $metadata = array_merge((array) ($existing?->metadata ?? []), [
                'imported' => true,
                'import_key' => $idempotencyKey,
                'imported_at' => now()->toIso8601String(),
                'unmapped' => $unmapped,
                'stale' => false,
            ]);
            unset($metadata['stale_at'], $metadata['stale_reason']);

            YunxiaoWorkitem::updateOrCreate(
                ['tenant_id' => $tenantId, 'external_workitem_id' => $externalId],
                [
                    'connection_id' => (int) $connection->connection_id,
                    'yunxiao_project_id' => (int) $project->yunxiao_project_id,
                    'serial_number' => (string) ($remote['serial_number'] ?? $existing?->serial_number ?? ''),
                    'workitem_type_id' => $this->stringOrNull($remote['workitem_type_id'] ?? null) ?? ($payload['workitem_type_id'] ?? null),
                    'subject' => (string) ($remote['subject'] ?? $payload['title'] ?? ''),
                    'status_id' => $resolvedStatusId,
                    'status_name' => $this->stringOrNull($remote['status_name'] ?? null) ?? $existing?->status_name,
                    'assigned_to' => $this->stringOrNull($remote['assigned_to'] ?? null) ?? ($payload['assigned_to'] ?? null),
                    'gmt_created' => $this->toDate($remote['gmt_created'] ?? null) ?? $existing?->gmt_created,
                    'gmt_modified' => $this->toDate($remote['gmt_modified'] ?? null) ?? $existing?->gmt_modified,
                    'fetched_at' => now(),
                    'metadata' => $metadata,
                ],
            );

            return true;
        } catch (\Throwable) {
            // 投影失败不回滚云效已创建项；external id 已在账本与响应里，下一轮同步可补投影。
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function itemResult(int $index, string $status, string $idempotencyKey, YunxiaoWorkitemImport $ledger, array $extra = []): array
    {
        return array_merge([
            'index' => $index,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'external_workitem_id' => $ledger->external_workitem_id,
            'serial_number' => $ledger->serial_number,
            'workitem_type_id' => $ledger->workitem_type_id,
            'assigned_to' => $ledger->assigned_to,
            'status_applied' => (bool) (($ledger->metadata ?? [])['status_applied'] ?? true),
            'warning' => null,
            'reason' => null,
            'error' => null,
            'attempts' => (int) $ledger->attempts,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFailure(int $index, string $reason, string $error, string $idempotencyKey = ''): array
    {
        return [
            'index' => $index,
            'status' => 'failed',
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'external_workitem_id' => null,
            'serial_number' => null,
            'workitem_type_id' => null,
            'assigned_to' => null,
            'status_applied' => false,
            'warning' => null,
            'reason' => $reason,
            'error' => $error,
            'attempts' => 0,
        ];
    }

    /**
     * 业务优先级别名 → 云效优先级字段值 ID；未配置的别名原样透传（不猜测）。
     */
    private function resolvePriority(string $priority): ?string
    {
        if ($priority === '') {
            return null;
        }

        $map = (array) config('yunxiao.import.priority_map', []);
        $mapped = $map[$priority] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : $priority;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        if (isset($payload['custom_field_values']) && is_array($payload['custom_field_values'])) {
            ksort($payload['custom_field_values']);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded === false ? serialize($payload) : $encoded);
    }

    /**
     * 自定义字段只接受标量或标量数组（云效多值字段用逗号拼接）。
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private function scalarMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || $value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $parts = array_map(static fn ($item): string => (string) $item, $value);
                $normalized[$key] = implode(',', $parts);

                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeError(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? '云效工作项导入失败' : mb_substr($message, 0, 1000);
    }
}
