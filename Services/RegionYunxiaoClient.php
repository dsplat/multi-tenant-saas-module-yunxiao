<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Support\YunxiaoUrlGuard;

/**
 * 云效 Region OpenAPI 客户端（P0）
 *
 * - 路径固定为 `/oapi/v1/projex/...`，Region 路径不拼 organizationId；
 * - token 只出现在请求头，绝不进日志/异常/返回值；
 * - 响应统一解包并归一化为稳定 snake_case 键（见 YunxiaoClientContract）。
 */
class RegionYunxiaoClient implements YunxiaoClientContract
{
    private const PATH_PREFIX = '/oapi/v1/projex';

    /**
     * 工作项搜索的默认大类（公开 projex 契约：需求 / 任务 / 缺陷）。
     */
    private const WORKITEM_CATEGORIES = 'Req,Task,Bug';

    public function __construct(
        private readonly Factory $http,
        private readonly YunxiaoUrlGuard $guard,
    ) {}

    public function listProjects(IntegrationConnection $connection, array $query = []): array
    {
        // 实例域没有 `GET /projects`（404），列表语义是 `POST projects:search`。
        $data = $this->request($connection, 'POST', self::PATH_PREFIX . '/projects:search', [], [
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, (int) ($query['per_page'] ?? 50)),
        ]);

        return array_values(array_map(
            fn (array $item): array => $this->normalizeProject($item),
            $this->itemsOf($data),
        ));
    }

    public function getProject(IntegrationConnection $connection, string $externalProjectId): array
    {
        $data = $this->request($connection, 'GET', self::PATH_PREFIX . '/projects/' . rawurlencode($externalProjectId));

        return $this->normalizeProject($this->firstOf($data));
    }

    public function createProject(IntegrationConnection $connection, array $payload): array
    {
        $data = $this->request($connection, 'POST', self::PATH_PREFIX . '/projects', [], array_filter([
            'name' => $payload['name'] ?? null,
            'customCode' => $payload['custom_code'] ?? null,
            'templateId' => $payload['template_id'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'description' => $payload['description'] ?? null,
            'projectManagerId' => $payload['project_manager'] ?? null,
            'memberIds' => $payload['members'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== []));

        return $this->normalizeProject($this->firstOf($data));
    }

    public function listTemplates(IntegrationConnection $connection): array
    {
        // 实例域模板集合是 `projectTemplates`；`/templates` 返回 404。
        $data = $this->request($connection, 'GET', self::PATH_PREFIX . '/projectTemplates');

        return array_values(array_map(
            fn (array $item): array => $this->normalizeTemplate($item),
            $this->itemsOf($data),
        ));
    }

    public function listWorkitems(IntegrationConnection $connection, string $externalProjectId, array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? (int) config('yunxiao.api.page_size', 100)));

        // 实例域工作项列表是 `POST workitems:search`（项目 GET 子资源不存在）。
        $body = [
            'spaceId' => $externalProjectId,
            'category' => self::WORKITEM_CATEGORIES,
            'page' => $page,
            'perPage' => $perPage,
            'orderBy' => 'gmtModified',
            'sort' => 'desc',
        ];

        $conditions = $this->workitemConditions($query['updated_since'] ?? null, $query['updated_until'] ?? null);

        if ($conditions !== null) {
            $body['conditions'] = $conditions;
        }

        [$data, $headers] = $this->requestWithMeta(
            $connection,
            'POST',
            self::PATH_PREFIX . '/workitems:search',
            [],
            $body,
        );

        $items = array_values(array_map(
            fn (array $item): array => $this->normalizeWorkitem($item),
            $this->itemsOf($data),
        ));

        // 搜索接口返回数组，总数在 `x-total` 响应头；没有该头时按「整页即可能还有下一页」。
        $total = $this->totalFromHeaders($headers);
        $hasMore = $total !== null ? $page * $perPage < $total : count($items) >= $perPage;

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    public function getWorkitem(IntegrationConnection $connection, string $externalWorkitemId): array
    {
        $data = $this->request($connection, 'GET', self::PATH_PREFIX . '/workitems/' . rawurlencode($externalWorkitemId));

        return $this->normalizeWorkitem($this->firstOf($data));
    }

    public function updateWorkitem(IntegrationConnection $connection, string $externalWorkitemId, array $payload): array
    {
        $data = $this->request(
            $connection,
            'PUT',
            self::PATH_PREFIX . '/workitems/' . rawurlencode($externalWorkitemId),
            [],
            array_filter([
                'statusId' => $payload['status_id'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        );

        return $this->normalizeWorkitem($this->firstOf($data));
    }

    public function addComment(IntegrationConnection $connection, string $externalWorkitemId, string $comment): array
    {
        $data = $this->request(
            $connection,
            'POST',
            self::PATH_PREFIX . '/workitems/' . rawurlencode($externalWorkitemId) . '/comments',
            [],
            ['content' => $comment],
        );

        return is_array($data) ? $data : [];
    }

    public function listMembers(IntegrationConnection $connection, string $externalProjectId): array
    {
        $data = $this->request(
            $connection,
            'GET',
            self::PATH_PREFIX . '/projects/' . rawurlencode($externalProjectId) . '/members',
        );

        return array_values(array_map(
            static fn (array $item): array => [
                'id' => (string) ($item['id'] ?? $item['identifier'] ?? $item['userId'] ?? $item['user_id'] ?? $item['account'] ?? ''),
                'name' => (string) ($item['name'] ?? $item['displayName'] ?? $item['display_name'] ?? ''),
            ],
            $this->itemsOf($data),
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(
        IntegrationConnection $connection,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        return $this->requestWithMeta($connection, $method, $path, $query, $body)[0];
    }

    /**
     * 同 request()，并返回响应头（列表分页用 `x-total`）。
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array{0: array<string, mixed>, 1: array<string, array<string>>}
     */
    private function requestWithMeta(
        IntegrationConnection $connection,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        $baseUrl = $connection->base_url ?: (string) config('yunxiao.api.base_url');
        $baseUrl = $this->guard->assertSafe($baseUrl);

        $credentials = (array) ($connection->credentials ?? []);
        $token = (string) ($credentials['token'] ?? '');

        if ($token === '') {
            throw new YunxiaoConfigurationException('云效连接缺少访问令牌（credentials.token）', YunxiaoConfigurationException::REASON_TOKEN_MISSING);
        }

        $timeout = (int) ($connection->config['timeout'] ?? config('yunxiao.api.timeout', 10));
        $headers = ['Accept' => 'application/json'];
        $authStyle = (string) ($connection->config['auth_style'] ?? config('yunxiao.api.auth_style', 'token'));

        if ($authStyle === 'bearer') {
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $headers['x-yunxiao-token'] = $token;
        }

        $pending = $this->http->timeout(max(1, $timeout))->withHeaders($headers);
        $url = $baseUrl . $path;

        try {
            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                'PUT' => $pending->put($url, $body ?? []),
                default => throw new YunxiaoApiException('不支持的云效请求方法', 500, YunxiaoApiException::REASON_UNKNOWN),
            };
        } catch (ConnectionException) {
            // 不把 URL/请求头放进异常：URL 无 token，但仍保持统一脱敏摘要。
            throw new YunxiaoApiException('云效 API 不可达（网络连接失败）', 503, YunxiaoApiException::REASON_UNAVAILABLE, true);
        }

        if ($response->failed()) {
            throw YunxiaoApiException::fromHttpStatus($response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new YunxiaoApiException('云效 API 返回内容无法解析', 502, YunxiaoApiException::REASON_UNKNOWN);
        }

        return [$this->unwrap($json), $response->headers()];
    }

    /**
     * 增量游标 → projex 搜索条件（日期条件用毫秒时间戳，与公开 projex 契约一致）。
     *
     * E1：`$updatedUntil` 由调用方在整轮同步开始时固定，缺省才取当前时间；
     * 逐页取 now 会让分页在同步过程中随远端写入漂移，是漏数根因之一。
     *
     * @return string|null JSON 字符串；无有效游标时返回 null（首轮全量）
     */
    private function workitemConditions(mixed $updatedSince, mixed $updatedUntil = null): ?string
    {
        $from = $this->toMilliseconds($updatedSince);

        if ($from === null) {
            return null;
        }

        $until = $this->toMilliseconds($updatedUntil) ?? (int) round(microtime(true) * 1000);

        $condition = [
            'className' => 'date',
            'fieldIdentifier' => 'gmtModified',
            'format' => 'date',
            'operator' => 'BETWEEN',
            'value' => [(string) $from],
            'toValue' => (string) $until,
        ];

        // 契约形状：conditionGroups 是「组」列表，每个组是「条件」列表。
        $json = json_encode([
            'conditionGroups' => [[$condition]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    /**
     * 游标既可能是毫秒/秒时间戳，也可能是 ISO 时间串；统一成毫秒。
     */
    private function toMilliseconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? ($value < 100_000_000_000 ? $value * 1000 : $value) : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $text) === 1) {
            $int = (int) $text;

            return $int > 0 ? ($int < 100_000_000_000 ? $int * 1000 : $int) : null;
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? null : $timestamp * 1000;
    }

    /**
     * @param  array<string, array<string>>  $headers
     */
    private function totalFromHeaders(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'x-total') {
                continue;
            }

            $raw = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
            $raw = trim($raw);

            return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
        }

        return null;
    }

    /**
     * Region OpenAPI 常见包装：`{success: true, data: ...}`。
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function unwrap(array $json): array
    {
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function itemsOf(array $data): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach (['items', 'list', 'records', 'templates', 'projects', 'workitems', 'members'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }

        return [$data];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function firstOf(array $data): array
    {
        $items = $this->itemsOf($data);

        return $items[0] ?? [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeProject(array $item): array
    {
        return [
            'external_project_id' => (string) $this->pick($item, ['id', 'projectId', 'project_id', 'identifier']),
            'name' => (string) $this->pick($item, ['name', 'projectName', 'project_name']),
            'custom_code' => $this->pick($item, ['customCode', 'custom_code']),
            'description' => $this->pick($item, ['description']),
            'scope' => $this->pick($item, ['scope', 'visibility']),
            'template_id' => $this->pick($item, ['templateId', 'template_id']),
            'status' => $this->pick($item, ['status', 'state']),
            'metadata' => ['raw' => $item['metadata'] ?? null],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeWorkitem(array $item): array
    {
        $status = $item['status'] ?? null;
        $statusId = is_array($status) ? ($status['id'] ?? null) : null;
        $statusName = is_array($status) ? ($status['name'] ?? null) : null;

        return [
            'external_workitem_id' => (string) $this->pick($item, ['id', 'workitemId', 'workitem_id', 'identifier']),
            'serial_number' => $this->pick($item, ['serialNumber', 'serial_number', 'serial']),
            'workitem_type_id' => $this->pick($item, ['workitemTypeId', 'workitem_type_id', 'typeId', 'type']),
            'subject' => (string) $this->pick($item, ['subject', 'title', 'name']),
            'status_id' => $statusId ?? $this->pick($item, ['statusId', 'status_id']),
            'status_name' => $statusName ?? $this->pick($item, ['statusName', 'status_name', 'statusLabel']),
            'assigned_to' => $this->pick($item, ['assignedTo', 'assigned_to', 'assigneeId', 'assignee']),
            'gmt_created' => $this->pick($item, ['gmtCreate', 'gmt_created', 'createdAt', 'created_at']),
            'gmt_modified' => $this->pick($item, ['gmtModified', 'gmt_modified', 'updatedAt', 'updated_at']),
            'description' => $this->pick($item, ['description']),
            'priority' => $this->pick($item, ['priority']),
            'iteration_id' => $this->pick($item, ['iterationId', 'iteration_id', 'sprintId']),
            'creator' => $this->pick($item, ['creator', 'creatorId', 'createdBy']),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeTemplate(array $item): array
    {
        $types = [];
        foreach (['workitemTypes', 'workitem_types', 'types'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                foreach ($item[$key] as $type) {
                    if (! is_array($type)) {
                        continue;
                    }
                    $types[] = [
                        'id' => (string) ($type['id'] ?? $type['typeId'] ?? $type['workitemTypeId'] ?? ''),
                        'name' => (string) ($type['name'] ?? ''),
                    ];
                }
                break;
            }
        }

        $statuses = [];
        foreach (['statuses', 'statusList', 'workflowStatuses'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                foreach ($item[$key] as $status) {
                    if (! is_array($status)) {
                        continue;
                    }
                    $statuses[] = [
                        'id' => (string) ($status['id'] ?? $status['statusId'] ?? ''),
                        'name' => (string) ($status['name'] ?? ''),
                        'workitem_type_id' => $status['workitemTypeId'] ?? $status['workitem_type_id'] ?? null,
                    ];
                }
                break;
            }
        }

        return [
            'template_id' => (string) ($item['id'] ?? $item['templateId'] ?? $item['template_id'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'workitem_types' => array_values(array_filter($types, static fn (array $type): bool => $type['id'] !== '')),
            'statuses' => array_values(array_filter($statuses, static fn (array $status): bool => $status['id'] !== '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private function pick(array $item, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                return $item[$key];
            }
        }

        return null;
    }
}
