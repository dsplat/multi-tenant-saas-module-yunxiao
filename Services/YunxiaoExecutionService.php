<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;

/**
 * 项目执行读取（issue #48）：迭代 / 里程碑 / 风险 / 进度汇总。
 *
 * 设计取舍：
 * - 云效 projex 有 ListSprints、ListMilestones，**没有**风险与统计接口；
 *   风险 = 工作项 `category=Risk`，进度 = 客户端按工作项聚合；
 * - 只读，不触发任何写；
 * - 进度聚合按 category 分轮读取，单个 category 失败只标记 partial，不拖垮整体；
 * - 项目必须先解析为本租户的 YunxiaoProject 投影，外部项目 ID 只从投影取，
 *   不接受请求方直接指定任意云效项目 ID（租户/组织/项目隔离）。
 */
class YunxiaoExecutionService
{
    /**
     * 进度聚合默认覆盖的工作项大类（风险含在内）。
     *
     * @var list<string>
     */
    public const DEFAULT_PROGRESS_CATEGORIES = ['Req', 'Task', 'Bug', 'Risk'];

    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
        private readonly YunxiaoProjectService $projects,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{project: YunxiaoProject, connection_id: int, items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function sprints(int $tenantId, int $yunxiaoProjectId, array $query = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $query): array {
            [$project, $connection] = $this->resolve($tenantId, $yunxiaoProjectId);

            return $this->envelope($project, $this->client->listSprints(
                $connection,
                (string) $project->external_project_id,
                $this->listQuery($query),
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{project: YunxiaoProject, connection_id: int, items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function milestones(int $tenantId, int $yunxiaoProjectId, array $query = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $query): array {
            [$project, $connection] = $this->resolve($tenantId, $yunxiaoProjectId);

            return $this->envelope($project, $this->client->listMilestones(
                $connection,
                (string) $project->external_project_id,
                $this->listQuery($query),
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{project: YunxiaoProject, connection_id: int, items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    public function risks(int $tenantId, int $yunxiaoProjectId, array $query = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $query): array {
            [$project, $connection] = $this->resolve($tenantId, $yunxiaoProjectId);

            return $this->envelope($project, $this->client->listRisks(
                $connection,
                (string) $project->external_project_id,
                $this->listQuery($query),
            ));
        });
    }

    /**
     * 项目进度聚合（工作项统计）。
     *
     * 完成率只有在「已完成状态集合」显式给出时才计算（请求参数优先，其次连接配置
     * `config.progress_done_status_ids` / `progress_done_status_names`）；
     * 不按状态名猜「完成」，避免把「已完成待验收」这类状态误判。
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function progress(int $tenantId, int $yunxiaoProjectId, array $options = []): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $yunxiaoProjectId, $options): array {
            [$project, $connection] = $this->resolve($tenantId, $yunxiaoProjectId);
            $externalProjectId = (string) $project->external_project_id;
            $config = (array) ($connection->config ?? []);

            $categories = $this->progressCategories($options['categories'] ?? null);
            $doneStatusIds = $this->stringList($options['done_status_ids'] ?? ($config['progress_done_status_ids'] ?? []));
            $doneStatusNames = $this->stringList($options['done_status_names'] ?? ($config['progress_done_status_names'] ?? []));
            $pageSize = $this->clampInt($options['page_size'] ?? config('yunxiao.progress.page_size', 100), 1, 200);
            $maxPages = $this->clampInt($options['max_pages'] ?? config('yunxiao.progress.max_pages', 5), 1, 50);

            $byCategory = array_fill_keys($categories, 0);
            $byStatus = [];
            $total = 0;
            $unassigned = 0;
            $done = 0;
            $overdue = 0;
            $truncated = false;
            $errors = [];
            $completionDefined = $doneStatusIds !== [] || $doneStatusNames !== [];
            $now = time();

            foreach ($categories as $category) {
                try {
                    for ($page = 1; $page <= $maxPages; $page++) {
                        $result = $this->client->listWorkitems($connection, $externalProjectId, [
                            'category' => $category,
                            'page' => $page,
                            'per_page' => $pageSize,
                        ]);

                        $items = (array) ($result['items'] ?? []);

                        foreach ($items as $item) {
                            if (! is_array($item)) {
                                continue;
                            }

                            $total++;
                            $byCategory[$category]++;
                            $this->countStatus($byStatus, $item);

                            $assignedTo = trim((string) ($item['assigned_to'] ?? ''));

                            if ($assignedTo === '') {
                                $unassigned++;
                            }

                            $isDone = $this->isDone($item, $doneStatusIds, $doneStatusNames);

                            if ($isDone) {
                                $done++;
                            }

                            if ($completionDefined && ! $isDone) {
                                $dueAt = $this->dueAt($item['due_date'] ?? null);

                                if ($dueAt !== null && $dueAt < $now) {
                                    $overdue++;
                                }
                            }
                        }

                        if (! (bool) ($result['has_more'] ?? false) || $items === []) {
                            break;
                        }

                        if ($page === $maxPages) {
                            // 读满上限仍有下一页：计数是下界，显式标记，避免下游当成全量。
                            $truncated = true;
                        }
                    }
                } catch (YunxiaoException $e) {
                    // 单类失败降级：该 category 计 0，原因进 errors；其它 category 继续。
                    $errors[$category] = $e instanceof YunxiaoApiException ? $e->reason : 'configuration_error';
                }
            }

            uasort($byStatus, static fn (array $a, array $b): int => [$b['count'], (string) $a['status_name']] <=> [$a['count'], (string) $b['status_name']]);

            return [
                'project' => $project,
                'connection_id' => (int) $project->connection_id,
                'external_project_id' => $externalProjectId,
                'generated_at' => now()->toIso8601String(),
                'categories' => $categories,
                'total' => $total,
                'by_category' => $byCategory,
                'by_status' => array_values($byStatus),
                'unassigned_count' => $unassigned,
                'completion' => [
                    'defined' => $completionDefined,
                    'done_status_ids' => $doneStatusIds,
                    'done_status_names' => $doneStatusNames,
                    'done_count' => $done,
                    'rate' => $completionDefined && $total > 0 ? round($done / $total, 4) : null,
                ],
                // 完成集合未定义时无法排除已完成项，逾期数不可判定，返回 null 而不是猜。
                'overdue_count' => $completionDefined ? $overdue : null,
                'truncated' => $truncated,
                'partial' => $errors !== [],
                'errors' => $errors,
            ];
        });
    }

    /**
     * 项目投影 + 连接解析：一律走本租户投影，外部项目 ID 不接受请求方直传。
     *
     * @return array{0: YunxiaoProject, 1: IntegrationConnection}
     */
    private function resolve(int $tenantId, int $yunxiaoProjectId): array
    {
        $project = $this->projects->find($tenantId, $yunxiaoProjectId);
        $connection = $this->connections->find($tenantId, (int) $project->connection_id);

        return [$project, $connection];
    }

    /**
     * @param  array{items: list<array<string, mixed>>, has_more: bool, total: int|null}  $page
     * @return array{project: YunxiaoProject, connection_id: int, items: list<array<string, mixed>>, has_more: bool, total: int|null}
     */
    private function envelope(YunxiaoProject $project, array $page): array
    {
        return [
            'project' => $project,
            'connection_id' => (int) $project->connection_id,
            'items' => $page['items'],
            'has_more' => $page['has_more'],
            'total' => $page['total'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function listQuery(array $query): array
    {
        $result = [];

        if (array_key_exists('status', $query) && $query['status'] !== null && $query['status'] !== '') {
            $result['status'] = $query['status'];
        }

        foreach (['page', 'per_page'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $result[$key] = (int) $query[$key];
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function progressCategories(mixed $value): array
    {
        $categories = $this->stringList($value);

        if ($categories === []) {
            return self::DEFAULT_PROGRESS_CATEGORIES;
        }

        $result = [];

        foreach ($categories as $category) {
            $normalized = ucfirst(strtolower($category));

            if (! in_array($normalized, self::DEFAULT_PROGRESS_CATEGORIES, true)) {
                throw new YunxiaoConfigurationException(
                    sprintf('不支持的工作项大类：%s（可选：%s）', $category, implode(',', self::DEFAULT_PROGRESS_CATEGORIES)),
                    YunxiaoConfigurationException::REASON_INVALID_CATEGORY,
                );
            }

            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param  array<string, array{status_id: string|null, status_name: string|null, count: int}>  $byStatus
     * @param  array<string, mixed>  $item
     */
    private function countStatus(array &$byStatus, array $item): void
    {
        $statusId = trim((string) ($item['status_id'] ?? ''));
        $statusName = trim((string) ($item['status_name'] ?? ''));
        $key = $statusId !== '' ? $statusId : ($statusName !== '' ? 'name:' . $statusName : 'unknown');

        if (! isset($byStatus[$key])) {
            $byStatus[$key] = [
                'status_id' => $statusId !== '' ? $statusId : null,
                'status_name' => $statusName !== '' ? $statusName : null,
                'count' => 0,
            ];
        }

        $byStatus[$key]['count']++;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $doneStatusIds
     * @param  list<string>  $doneStatusNames
     */
    private function isDone(array $item, array $doneStatusIds, array $doneStatusNames): bool
    {
        $statusId = trim((string) ($item['status_id'] ?? ''));
        $statusName = trim((string) ($item['status_name'] ?? ''));

        return ($statusId !== '' && in_array($statusId, $doneStatusIds, true))
            || ($statusName !== '' && in_array($statusName, $doneStatusNames, true));
    }

    /**
     * 截止日期归一化为秒级时间戳；支持 `Y-m-d` / ISO / 毫秒或秒时间戳，无法解析返回 null。
     */
    private function dueAt(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            $int = (int) $value;

            if ($int <= 0) {
                return null;
            }

            return $int > 100_000_000_000 ? (int) round($int / 1000) : $int;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $items = array_map(static fn ($item): string => trim((string) $item), $value);

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
