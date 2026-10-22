<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Modules\Integration\Models\ExternalMapping;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Models\IntegrationOutboxMessage;
use MultiTenantSaas\Modules\Integration\Services\ExternalMappingService;
use MultiTenantSaas\Modules\Integration\Services\IntegrationOutboxService;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoWritebackDead;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoWritebackFailed;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoWritebackSucceeded;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitem;

/**
 * GitLab → 云效写回（GitLab 侧只提供事件输入，本模块不依赖 Gitlab 模块）
 *
 * - 按 serialNumber 解析工作项：优先 ExternalMappingService，回落投影表；
 * - 未匹配 → unmatched（正常返回，不报错、不入队）；
 * - 状态映射来自模板 status_map；无映射/未知状态 → 只评论不改状态；
 * - 入队 IntegrationOutboxMessage（幂等键 gitlab:{project}:{mr|pipeline}:{object}:{action}）；
 * - 连接 writeback_enabled=false / token 过期 → 拒绝写回；
 * - 投递成功/失败/死信分别派发事件，死信可人工重试。
 */
class YunxiaoWritebackService
{
    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
        private readonly YunxiaoTemplateMapService $templates,
        private readonly ExternalMappingService $mappings,
        private readonly IntegrationOutboxService $outbox,
    ) {}

    /**
     * 接收 GitLab 事件并幂等入队。
     *
     * 事件字段：kind(mr|pipeline)、action、project、object、task_number、
     * summary、url、event_type（可选，覆盖推导的状态映射键）。
     *
     * @param  array<string, mixed>  $event
     * @return array{matched:bool, queued:bool, message_id:int|null, status_action:string, reason:string|null, workitem_id:int|null}
     */
    public function handle(int $tenantId, array $event): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $event): array {
            $kind = $this->normalizeKind((string) ($event['kind'] ?? $event['object_type'] ?? ''));
            $action = $this->normalizeAction((string) ($event['action'] ?? ''));
            $project = trim((string) ($event['project'] ?? $event['project_id'] ?? ''));
            $object = trim((string) ($event['object'] ?? $event['object_id'] ?? $event['mr_id'] ?? $event['pipeline_id'] ?? ''));
            $taskNumber = trim((string) ($event['task_number'] ?? $event['serial_number'] ?? $event['task'] ?? ''));

            if ($kind === '' || $action === '' || $project === '' || $object === '') {
                throw new YunxiaoConfigurationException(
                    '写回事件缺少 kind/action/project/object',
                    YunxiaoConfigurationException::REASON_INVALID_EVENT,
                );
            }

            $workitem = $this->resolveWorkitem($tenantId, $taskNumber);

            if (! $workitem instanceof YunxiaoWorkitem) {
                return [
                    'matched' => false,
                    'queued' => false,
                    'message_id' => null,
                    'status_action' => 'unmatched',
                    'reason' => 'workitem_not_found',
                    'workitem_id' => null,
                ];
            }

            $projectModel = YunxiaoProject::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($workitem->yunxiao_project_id)
                ->first();

            if (! $projectModel instanceof YunxiaoProject) {
                return [
                    'matched' => false,
                    'queued' => false,
                    'message_id' => null,
                    'status_action' => 'unmatched',
                    'reason' => 'project_not_found',
                    'workitem_id' => (int) $workitem->yunxiao_workitem_id,
                ];
            }

            $connection = $this->connections->assertWritebackReady($tenantId, (int) $projectModel->connection_id);

            $statusMap = $this->templates->statusMapForProject($tenantId, $projectModel);
            $statusId = $this->resolveStatusId($statusMap, $kind, $action, $event);
            $knownStatuses = $this->templates->knownStatusIdsForProject($tenantId, $projectModel);

            $statusAction = 'comment_only';

            if ($statusId !== null && ($knownStatuses === [] || in_array($statusId, $knownStatuses, true))) {
                $statusAction = 'status_and_comment';
            } else {
                $statusId = null;
            }

            $comment = $this->buildComment($event);
            $idempotencyKey = sprintf('gitlab:%s:%s:%s:%s', $project, $kind, $object, $action);

            $message = $this->outbox->enqueue(
                $tenantId,
                (int) $connection->connection_id,
                $idempotencyKey,
                $statusId !== null ? 'workitem.writeback_status' : 'workitem.writeback_comment',
                [
                    'workitem_id' => (int) $workitem->yunxiao_workitem_id,
                    'external_workitem_id' => (string) $workitem->external_workitem_id,
                    'serial_number' => (string) $workitem->serial_number,
                    'status_id' => $statusId,
                    'comment' => $comment,
                    'event' => $this->eventKey($kind, $action, $event),
                    'gitlab' => [
                        'project' => $project,
                        'kind' => $kind,
                        'object' => $object,
                        'action' => $action,
                    ],
                ],
            );

            return [
                'matched' => true,
                'queued' => true,
                'message_id' => (int) $message->message_id,
                'status_action' => $statusAction,
                'reason' => $statusAction === 'comment_only' ? 'status_not_mapped' : null,
                'workitem_id' => (int) $workitem->yunxiao_workitem_id,
            ];
        });
    }

    /**
     * 评论草稿（AI L1）：只生成文本，不入队、不提交。
     *
     * @param  array<string, mixed>  $event
     */
    public function draftComment(array $event): string
    {
        return $this->buildComment($event);
    }

    /**
     * 投递到期写回消息（按连接串行认领）。
     *
     * @return array{connections:int, claimed:int, sent:int, failed:int, dead:int, errors:list<string>}
     */
    public function process(int $tenantId, ?int $connectionId = null, int $limit = 50): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $limit): array {
            $stats = ['connections' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0, 'errors' => []];

            foreach ($this->connectionsFor($tenantId, $connectionId) as $connection) {
                $stats['connections']++;
                $messages = $this->outbox->claim($tenantId, (int) $connection->connection_id, $limit);

                foreach ($messages as $message) {
                    $stats['claimed']++;

                    try {
                        $this->deliver($connection, $message);
                        $this->outbox->markSent($tenantId, (int) $message->message_id);

                        Event::dispatch(new YunxiaoWritebackSucceeded(
                            $tenantId,
                            (int) $message->message_id,
                            (int) ($message->payload['workitem_id'] ?? 0),
                            (string) ($message->payload['external_workitem_id'] ?? ''),
                            (string) $message->action,
                        ));

                        $stats['sent']++;
                    } catch (\Throwable $e) {
                        $error = $this->safeError($e);
                        $this->outbox->markFailed(
                            $tenantId,
                            (int) $message->message_id,
                            $error,
                            (int) config('yunxiao.writeback.max_attempts', 5),
                        );

                        $refreshed = IntegrationOutboxMessage::query()
                            ->where('tenant_id', $tenantId)
                            ->whereKey($message->message_id)
                            ->first();

                        $attempts = (int) ($refreshed?->attempts ?? ($message->attempts + 1));
                        $payload = (array) ($message->payload ?? []);

                        if ($refreshed?->status === IntegrationOutboxMessage::STATUS_DEAD) {
                            Event::dispatch(new YunxiaoWritebackDead(
                                $tenantId,
                                (int) $message->message_id,
                                (int) ($payload['workitem_id'] ?? 0),
                                $attempts,
                                $error,
                            ));
                            $stats['dead']++;
                        } else {
                            Event::dispatch(new YunxiaoWritebackFailed(
                                $tenantId,
                                (int) $message->message_id,
                                (int) ($payload['workitem_id'] ?? 0),
                                $attempts,
                                $error,
                            ));
                            $stats['failed']++;
                        }

                        $stats['errors'][] = $error;
                    }
                }
            }

            return $stats;
        });
    }

    /**
     * 重试单条死信/失败消息（重置尝试次数并立即可投递）。
     */
    public function retry(int $tenantId, int $messageId): IntegrationOutboxMessage
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $messageId): IntegrationOutboxMessage {
            $message = $this->find($tenantId, $messageId);

            if (! in_array($message->status, [
                IntegrationOutboxMessage::STATUS_DEAD,
                IntegrationOutboxMessage::STATUS_FAILED,
            ], true)) {
                throw new YunxiaoConfigurationException(
                    '只有 failed/dead 状态的写回消息可以重试',
                    YunxiaoConfigurationException::REASON_ALREADY_SENT,
                );
            }

            $message->status = IntegrationOutboxMessage::STATUS_PENDING;
            $message->attempts = 0;
            $message->available_at = now();
            $message->last_error = null;
            $message->save();

            return $message->refresh();
        });
    }

    /**
     * 批量重试死信（dry-run 只统计不修改）。
     *
     * @return array{retried:int, message_ids:list<int>, dry_run:bool}
     */
    public function retryAll(int $tenantId, ?int $connectionId = null, bool $dryRun = false): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $dryRun): array {
            $connectionIds = array_map(
                static fn (IntegrationConnection $connection): int => (int) $connection->connection_id,
                $this->connectionsFor($tenantId, $connectionId),
            );

            $messages = IntegrationOutboxMessage::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('connection_id', $connectionIds)
                ->where('status', IntegrationOutboxMessage::STATUS_DEAD)
                ->orderBy('message_id')
                ->get();

            $ids = [];

            foreach ($messages as $message) {
                $ids[] = (int) $message->message_id;

                if ($dryRun) {
                    continue;
                }

                $message->status = IntegrationOutboxMessage::STATUS_PENDING;
                $message->attempts = 0;
                $message->available_at = now();
                $message->last_error = null;
                $message->save();
            }

            return ['retried' => count($ids), 'message_ids' => $ids, 'dry_run' => $dryRun];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $connectionIds = array_map(
            static fn (IntegrationConnection $connection): int => (int) $connection->connection_id,
            $this->allConnections($tenantId),
        );

        $query = IntegrationOutboxMessage::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('connection_id', $connectionIds);

        return $query
            ->when($filters['status'] ?? null, fn ($builder, $value) => $builder->where('status', (string) $value))
            ->when($filters['action'] ?? null, fn ($builder, $value) => $builder->where('action', (string) $value))
            ->when($filters['connection_id'] ?? null, fn ($builder, $value) => $builder->where('connection_id', (int) $value))
            ->orderByDesc('created_at')
            ->orderByDesc('message_id')
            ->paginate($perPage ?? (int) config('yunxiao.per_page', 20));
    }

    public function find(int $tenantId, int $messageId): IntegrationOutboxMessage
    {
        $message = IntegrationOutboxMessage::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($messageId)
            ->first();

        if (! $message instanceof IntegrationOutboxMessage) {
            throw new NotFoundException('写回消息不存在');
        }

        $connection = $this->connections->find($tenantId, (int) $message->connection_id);

        if ($connection->provider !== YunxiaoConnectionService::PROVIDER) {
            throw new NotFoundException('写回消息不存在');
        }

        return $message;
    }

    private function deliver(IntegrationConnection $connection, IntegrationOutboxMessage $message): void
    {
        $payload = (array) ($message->payload ?? []);
        $externalId = (string) ($payload['external_workitem_id'] ?? '');

        if ($externalId === '') {
            throw new YunxiaoException('写回消息缺少云效工作项 ID');
        }

        $statusId = (string) ($payload['status_id'] ?? '');
        $comment = (string) ($payload['comment'] ?? '');

        if ($statusId !== '') {
            try {
                $this->client->updateWorkitem($connection, $externalId, ['status_id' => $statusId]);
            } catch (YunxiaoApiException $e) {
                if ($e->reason !== YunxiaoApiException::REASON_CONFLICT) {
                    throw $e;
                }

                // 409/乐观锁：重新拉取最新工作项后重试一次；仍冲突则交给 outbox 退避/死信。
                $this->client->getWorkitem($connection, $externalId);
                $this->client->updateWorkitem($connection, $externalId, ['status_id' => $statusId]);
            }
        }

        if ($comment !== '') {
            $this->client->addComment($connection, $externalId, $comment);
        }
    }

    private function resolveWorkitem(int $tenantId, string $taskNumber): ?YunxiaoWorkitem
    {
        if ($taskNumber === '') {
            return null;
        }

        $mapping = $this->mappings->resolveBySource(
            $tenantId,
            YunxiaoConnectionService::PROVIDER,
            'workitem',
            $taskNumber,
        );

        if ($mapping instanceof ExternalMapping && $mapping->local_type === 'workitem') {
            $item = YunxiaoWorkitem::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($mapping->local_id)
                ->first();

            if ($item instanceof YunxiaoWorkitem) {
                return $item;
            }
        }

        return YunxiaoWorkitem::query()
            ->where('tenant_id', $tenantId)
            ->where('serial_number', $taskNumber)
            ->orderByDesc('yunxiao_workitem_id')
            ->first();
    }

    /**
     * @param  array<string, string>  $statusMap
     * @param  array<string, mixed>  $event
     */
    private function resolveStatusId(array $statusMap, string $kind, string $action, array $event): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            trim((string) ($event['event_type'] ?? $event['event'] ?? '')),
            $this->eventKey($kind, $action, $event),
            sprintf('%s.%s', $kind, $action),
            $action,
        ])));

        foreach ($candidates as $key) {
            if (isset($statusMap[$key]) && trim((string) $statusMap[$key]) !== '') {
                return trim((string) $statusMap[$key]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventKey(string $kind, string $action, array $event): string
    {
        $explicit = trim((string) ($event['event_type'] ?? ''));

        return $explicit !== '' ? $explicit : $kind . '_' . $action;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function buildComment(array $event): string
    {
        $summary = trim((string) ($event['summary'] ?? $event['title'] ?? ''));
        $url = trim((string) ($event['url'] ?? ''));
        $eventName = trim((string) ($event['event_type'] ?? ''));

        if ($eventName === '') {
            $eventName = $this->normalizeKind((string) ($event['kind'] ?? '')) . '_' . $this->normalizeAction((string) ($event['action'] ?? ''));
        }

        $comment = strtr((string) config('yunxiao.writeback.comment_template', "【平台对账】{event}\n{summary}\n{url}"), [
            '{event}' => $eventName,
            '{summary}' => $summary,
            '{url}' => $url,
        ]);

        $limit = max(1, (int) config('yunxiao.writeback.comment_max_length', 500));

        return mb_substr(trim($comment), 0, $limit);
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        $kind = str_replace(['-', ' '], '_', $kind);

        return match ($kind) {
            'merge_request', 'merge_request_event', 'mergerequest' => 'mr',
            'ci', 'pipeline_event' => 'pipeline',
            'mr', 'pipeline' => $kind,
            default => $kind,
        };
    }

    private function normalizeAction(string $action): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($action)));
    }

    /**
     * @return list<IntegrationConnection>
     */
    private function connectionsFor(int $tenantId, ?int $connectionId): array
    {
        if ($connectionId !== null) {
            return [$this->connections->find($tenantId, $connectionId)];
        }

        return array_values(array_filter(
            $this->allConnections($tenantId),
            static fn (IntegrationConnection $connection): bool => $connection->status === IntegrationConnection::STATUS_ACTIVE,
        ));
    }

    /**
     * @return list<IntegrationConnection>
     */
    private function allConnections(int $tenantId): array
    {
        return array_map(
            fn (array $connection): IntegrationConnection => $this->connections->find($tenantId, (int) $connection['connection_id']),
            $this->connections->list($tenantId),
        );
    }

    private function safeError(\Throwable $e): string
    {
        if ($e instanceof YunxiaoException) {
            return mb_substr($e->getMessage(), 0, 500);
        }

        return '云效写回失败';
    }
}
