<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Services\IntegrationConnectionService;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoAccountClientContract;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Events\YunxiaoConnectionChecked;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoException;
use MultiTenantSaas\Modules\Yunxiao\Support\YunxiaoUrlGuard;

/**
 * 云效连接服务（连接本身落 Integration integration_connections, provider=yunxiao）
 *
 * 安全约束：
 * - token 只写 Integration credentials（encrypted:array），任何出口都不回显；
 * - base_url 保存前过 SSRF 守卫；
 * - 保存/轮换前先列项目 + 模板，验证 token/组织/权限，结果写回 last_checked_*；
 * - token 过期只停止写回，投影仍可只读。
 */
class YunxiaoConnectionService
{
    public const PROVIDER = 'yunxiao';

    public function __construct(
        private readonly IntegrationConnectionService $connections,
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoAccountClientContract $accountClient,
        private readonly YunxiaoUrlGuard $urlGuard,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId): array
    {
        return array_map(
            fn (IntegrationConnection $connection): array => $this->sanitize($connection),
            $this->connections->forProvider($tenantId, self::PROVIDER),
        );
    }

    public function find(int $tenantId, int $connectionId): IntegrationConnection
    {
        $connection = $this->connections->find($tenantId, $connectionId);

        if (! $connection instanceof IntegrationConnection || $connection->provider !== self::PROVIDER) {
            throw new YunxiaoConfigurationException('云效连接不存在', YunxiaoConfigurationException::REASON_CONNECTION_NOT_FOUND);
        }

        return $connection;
    }

    /**
     * 取可写回连接（必须 active + writeback_enabled + token 未过期）。
     */
    public function assertWritebackReady(int $tenantId, int $connectionId): IntegrationConnection
    {
        $connection = $this->find($tenantId, $connectionId);

        if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
            throw new YunxiaoConfigurationException('云效连接已停用', YunxiaoConfigurationException::REASON_CONNECTION_DISABLED);
        }

        if (! $this->hasToken($connection)) {
            throw new YunxiaoConfigurationException('云效连接缺少 token', YunxiaoConfigurationException::REASON_TOKEN_MISSING);
        }

        if ($this->isTokenExpired($connection)) {
            throw YunxiaoConfigurationException::tokenExpired();
        }

        if (! (bool) ($connection->config['writeback_enabled'] ?? false)) {
            throw YunxiaoConfigurationException::writebackDisabled();
        }

        return $connection;
    }

    /**
     * 取当前租户的默认云效连接（首个 active，按 connection_id 稳定排序）。
     */
    public function activeConnection(int $tenantId, ?int $connectionId = null): IntegrationConnection
    {
        if ($connectionId !== null) {
            $connection = $this->find($tenantId, $connectionId);

            if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
                throw new YunxiaoConfigurationException('云效连接已停用', YunxiaoConfigurationException::REASON_CONNECTION_DISABLED);
            }

            return $connection;
        }

        foreach ($this->connections->forProvider($tenantId, self::PROVIDER) as $connection) {
            if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
                continue;
            }

            // 无 default_org_id 的 Account 连接只能做组织发现，不能顶替项目级连接。
            if ($this->isProjexUnavailableAccount($connection)) {
                continue;
            }

            return $connection;
        }

        throw new YunxiaoConfigurationException('租户尚未配置云效连接', YunxiaoConfigurationException::REASON_CONNECTION_NOT_FOUND);
    }

    private function isProjexUnavailableAccount(IntegrationConnection $connection): bool
    {
        return ProjexYunxiaoClient::isAccount($connection)
            && trim((string) (($connection->config ?? [])['default_org_id'] ?? '')) === '';
    }

    /**
     * 账号级连接（scope=Account）：显式 connection_id 优先，否则取首个 active Account 连接。
     */
    public function activeAccountConnection(int $tenantId, ?int $connectionId = null): IntegrationConnection
    {
        return $this->activeConnectionForScope($tenantId, ProjexYunxiaoClient::SCOPE_ACCOUNT, $connectionId);
    }

    /**
     * 按 scope 取 active 连接；显式指定时必须与 scope 匹配，绝不把其它站点连接顶上来。
     */
    public function activeConnectionForScope(int $tenantId, string $scope, ?int $connectionId = null): IntegrationConnection
    {
        if ($connectionId !== null) {
            $connection = $this->find($tenantId, $connectionId);

            if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
                throw new YunxiaoConfigurationException('云效连接已停用', YunxiaoConfigurationException::REASON_CONNECTION_DISABLED);
            }

            if (! $this->connectionHasScope($connection, $scope)) {
                throw new YunxiaoConfigurationException(
                    sprintf('云效连接 scope 不是 %s', $scope),
                    YunxiaoConfigurationException::REASON_SCOPE_MISMATCH,
                );
            }

            return $connection;
        }

        foreach ($this->connections->forProvider($tenantId, self::PROVIDER) as $connection) {
            if ($connection->status === IntegrationConnection::STATUS_ACTIVE && $this->connectionHasScope($connection, $scope)) {
                return $connection;
            }
        }

        throw new YunxiaoConfigurationException(
            sprintf('租户尚未配置 %s 级云效连接', $scope),
            YunxiaoConfigurationException::REASON_CONNECTION_NOT_FOUND,
        );
    }

    private function connectionHasScope(IntegrationConnection $connection, string $scope): bool
    {
        $actual = (string) (($connection->config ?? [])['scope'] ?? ProjexYunxiaoClient::SCOPE_REGION);

        return strcasecmp($actual, $scope) === 0;
    }

    /**
     * 新建连接：保存前测试（列项目/模板），失败不落库。
     *
     * @param  array{name:string, base_url:string, token:string, auth_type?:string, config?:array<string, mixed>}  $data
     */
    public function create(int $tenantId, array $data): IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $data): IntegrationConnection {
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                throw new YunxiaoConfigurationException('连接名称不能为空');
            }

            $config = $this->normalizeConfig((array) ($data['config'] ?? []), []);
            $baseUrl = $this->resolveBaseUrl($data['base_url'] ?? null, $config);
            $token = (string) ($data['token'] ?? '');

            if ($token === '') {
                throw new YunxiaoConfigurationException('云效 token 不能为空', YunxiaoConfigurationException::REASON_TOKEN_MISSING);
            }

            $probe = $this->probeModel($tenantId, $name, $baseUrl, $token, $config);
            $this->probe($probe);

            $connection = $this->connections->create($tenantId, $name, self::PROVIDER, [
                'base_url' => $baseUrl,
                'auth_type' => (string) ($data['auth_type'] ?? IntegrationConnection::AUTH_BEARER),
                'credentials' => ['token' => $token],
                'config' => $config,
                'status' => IntegrationConnection::STATUS_ACTIVE,
            ]);

            $this->connections->markChecked($tenantId, (int) $connection->connection_id);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, (int) $connection->connection_id, true, null));

            return $connection->refresh();
        });
    }

    /**
     * 更新连接；token / base_url / auth_style / scope / org_id 变更时重新测试。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int $connectionId, array $data): IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $data): IntegrationConnection {
            $connection = $this->find($tenantId, $connectionId);
            $config = $this->normalizeConfig((array) ($data['config'] ?? []), (array) ($connection->config ?? []));
            $baseUrl = $this->resolveBaseUrl($data['base_url'] ?? null, $config, $connection);
            $token = isset($data['token']) && $data['token'] !== '' ? (string) $data['token'] : null;
            $authStyleChanged = isset($data['config']['auth_style'])
                && (string) $data['config']['auth_style'] !== (string) ($connection->config['auth_style'] ?? 'token');
            // scope / organizationId 变更会改变路径前缀，必须重新探测。
            $scopeChanged = (string) ($config['scope'] ?? 'Region') !== (string) ($connection->config['scope'] ?? 'Region');
            $orgIdChanged = (string) ($config['org_id'] ?? '') !== (string) ($connection->config['org_id'] ?? '');

            if ($token !== null || $baseUrl !== $connection->base_url || $authStyleChanged || $scopeChanged || $orgIdChanged) {
                $probe = $this->probeModel(
                    $tenantId,
                    (string) ($data['name'] ?? $connection->name),
                    (string) $baseUrl,
                    $token ?? $this->tokenOf($connection),
                    $config,
                );
                $this->probe($probe);
            }

            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = (string) $data['name'];
            }

            // scope 切换会按站点改默认接入点；即使请求没显式带 base_url 也要落库。
            if (array_key_exists('base_url', $data) || $baseUrl !== (string) ($connection->base_url ?? '')) {
                $attributes['base_url'] = $baseUrl;
            }

            if (array_key_exists('auth_type', $data)) {
                $attributes['auth_type'] = (string) $data['auth_type'];
            }

            if ($token !== null) {
                $attributes['credentials'] = array_merge((array) ($connection->credentials ?? []), ['token' => $token]);
            }

            $attributes['config'] = $config;

            $updated = $this->connections->update($tenantId, $connectionId, $attributes);
            $this->connections->markChecked($tenantId, $connectionId);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, true, null));

            return $updated;
        });
    }

    /**
     * 轮换 token：先测新 token，成功才替换。
     */
    public function rotate(int $tenantId, int $connectionId, string $token, ?string $expiresAt = null): IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $token, $expiresAt): IntegrationConnection {
            $connection = $this->find($tenantId, $connectionId);

            if ($token === '') {
                throw new YunxiaoConfigurationException('新 token 不能为空', YunxiaoConfigurationException::REASON_TOKEN_MISSING);
            }

            $config = (array) ($connection->config ?? []);

            if ($expiresAt !== null && $expiresAt !== '') {
                $config['token_expires_at'] = $this->normalizeExpiry($expiresAt);
            }

            $baseUrl = $this->resolveBaseUrl(null, $config, $connection);

            $probe = $this->probeModel(
                $tenantId,
                (string) $connection->name,
                $baseUrl,
                $token,
                $config,
            );
            $this->probe($probe);

            $attributes = [
                'credentials' => array_merge((array) ($connection->credentials ?? []), ['token' => $token]),
                'config' => $config,
            ];

            if ($baseUrl !== (string) ($connection->base_url ?? '')) {
                $attributes['base_url'] = $baseUrl;
            }

            $updated = $this->connections->update($tenantId, $connectionId, $attributes);
            $this->connections->markChecked($tenantId, $connectionId);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, true, null));

            return $updated;
        });
    }

    /**
     * 连通性/权限测试，结果落 last_checked_*。
     *
     * - Region / Central：列项目 + 模板；
     * - Account：列组织 + 当前用户（账号级令牌的只读探测）。
     *
     * @return array{ok:bool, projects:int, templates:int, organizations:int, error:string|null}
     */
    public function test(int $tenantId, int $connectionId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId): array {
            $connection = $this->find($tenantId, $connectionId);

            try {
                if (ProjexYunxiaoClient::isAccount($connection)) {
                    $organizations = $this->accountClient->listOrganizations($connection);
                    $this->accountClient->currentUser($connection);
                    $counts = ['projects' => 0, 'templates' => 0, 'organizations' => count($organizations)];
                } else {
                    $projects = $this->client->listProjects($connection, ['page' => 1]);
                    $templates = $this->client->listTemplates($connection);
                    $counts = ['projects' => count($projects), 'templates' => count($templates), 'organizations' => 0];
                }
            } catch (\Throwable $e) {
                $error = $this->safeError($e);
                $this->connections->markChecked($tenantId, $connectionId, $error);
                Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, false, $error));

                return ['ok' => false, 'projects' => 0, 'templates' => 0, 'organizations' => 0, 'error' => $error];
            }

            $this->connections->markChecked($tenantId, $connectionId, null);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, true, null));

            return ['ok' => true, ...$counts, 'error' => null];
        });
    }

    /**
     * API 出口：绝不包含 credentials / token 片段。
     *
     * @return array<string, mixed>
     */
    public function sanitize(IntegrationConnection $connection): array
    {
        $config = (array) ($connection->config ?? []);
        $expiresAt = $config['token_expires_at'] ?? null;

        return [
            'connection_id' => (int) $connection->connection_id,
            'name' => $connection->name,
            'provider' => $connection->provider,
            'base_url' => $connection->base_url,
            'auth_type' => $connection->auth_type,
            'status' => $connection->status,
            'has_token' => $this->hasToken($connection),
            'token_expires_at' => $expiresAt,
            'token_expired' => $this->isTokenExpired($connection),
            'token_expiring_soon' => $this->isTokenExpiringSoon($connection),
            'scope' => $config['scope'] ?? 'Region',
            'org_id' => $config['org_id'] ?? null,
            'org_name' => $config['org_name'] ?? null,
            'default_org_id' => $config['default_org_id'] ?? null,
            'org_allowlist' => array_values((array) ($config['org_allowlist'] ?? [])),
            'region' => $config['region'] ?? null,
            'writeback_enabled' => (bool) ($config['writeback_enabled'] ?? false),
            'default_template_id' => $config['default_template_id'] ?? null,
            'timeout' => (int) ($config['timeout'] ?? config('yunxiao.api.timeout', 10)),
            'rate_limit' => (int) ($config['rate_limit'] ?? config('yunxiao.api.rate_limit', 5)),
            'last_checked_at' => $connection->last_checked_at?->format('Y-m-d H:i:s'),
            'last_check_error' => $connection->last_check_error,
            'created_at' => $connection->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $connection->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function hasToken(IntegrationConnection $connection): bool
    {
        return $this->tokenOf($connection) !== '';
    }

    public function isTokenExpired(IntegrationConnection $connection): bool
    {
        $expiresAt = $this->expiresAt($connection);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function isTokenExpiringSoon(IntegrationConnection $connection): bool
    {
        $expiresAt = $this->expiresAt($connection);

        if ($expiresAt === null || $expiresAt->isPast()) {
            return false;
        }

        $days = (int) config('yunxiao.token_expiry_warning_days', 30);

        return $expiresAt->lessThanOrEqualTo(now()->addDays($days));
    }

    private function tokenOf(IntegrationConnection $connection): string
    {
        $credentials = (array) ($connection->credentials ?? []);

        return trim((string) ($credentials['token'] ?? ''));
    }

    private function expiresAt(IntegrationConnection $connection): ?Carbon
    {
        $value = (array) ($connection->config ?? []);
        $value = $value['token_expires_at'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $input, array $existing): array
    {
        $config = $existing;

        $scope = $this->normalizeScope($input['scope'] ?? $existing['scope'] ?? ProjexYunxiaoClient::SCOPE_REGION);
        $config['scope'] = $scope;

        foreach (['org_id', 'org_name', 'region', 'default_template_id'] as $key) {
            if (array_key_exists($key, $input)) {
                $config[$key] = $input[$key] !== null ? (string) $input[$key] : null;
            }
        }

        if (array_key_exists('default_org_id', $input)) {
            $defaultOrgId = $input['default_org_id'] !== null ? trim((string) $input['default_org_id']) : '';
            $config['default_org_id'] = $defaultOrgId === '' ? null : $defaultOrgId;
        }

        if (array_key_exists('org_allowlist', $input)) {
            $config['org_allowlist'] = $this->normalizeOrgAllowlist($input['org_allowlist']);
        }

        if (array_key_exists('auth_style', $input)) {
            $style = (string) $input['auth_style'];

            if (! in_array($style, ['token', 'bearer'], true)) {
                throw new YunxiaoConfigurationException('auth_style 仅支持 token|bearer');
            }

            $config['auth_style'] = $style;
        }

        $config['timeout'] = (int) ($input['timeout'] ?? $config['timeout'] ?? config('yunxiao.api.timeout', 10));
        $config['rate_limit'] = (int) ($input['rate_limit'] ?? $config['rate_limit'] ?? config('yunxiao.api.rate_limit', 5));

        if ($config['timeout'] < 1 || $config['timeout'] > 120) {
            throw new YunxiaoConfigurationException('timeout 必须在 1–120 秒之间');
        }

        if ($config['rate_limit'] < 1) {
            throw new YunxiaoConfigurationException('rate_limit 必须大于 0');
        }

        if (array_key_exists('writeback_enabled', $input)) {
            $config['writeback_enabled'] = (bool) $input['writeback_enabled'];
        } else {
            $config['writeback_enabled'] = (bool) ($config['writeback_enabled'] ?? false);
        }

        if (array_key_exists('token_expires_at', $input)) {
            $config['token_expires_at'] = $input['token_expires_at'] !== null && $input['token_expires_at'] !== ''
                ? $this->normalizeExpiry((string) $input['token_expires_at'])
                : null;
        }

        // 中心版所有 projex 路径都带 organizationId；缺失时保存即失败，不落一个必坏的连接。
        if ($scope === ProjexYunxiaoClient::SCOPE_CENTRAL && trim((string) ($config['org_id'] ?? '')) === '') {
            throw new YunxiaoConfigurationException(
                'Central 组织连接必须提供 organizationId（config.org_id）',
                YunxiaoConfigurationException::REASON_ORG_MISSING,
            );
        }

        // 账号级：default_org_id 必须落在白名单内，否则项目级调用会被自己的边界拒绝。
        if ($scope === ProjexYunxiaoClient::SCOPE_ACCOUNT) {
            $defaultOrgId = trim((string) ($config['default_org_id'] ?? ''));
            $allowlist = array_values((array) ($config['org_allowlist'] ?? []));

            if ($defaultOrgId !== '' && $allowlist !== [] && ! in_array($defaultOrgId, $allowlist, true)) {
                throw new YunxiaoConfigurationException(
                    'default_org_id 必须在 org_allowlist 内',
                    YunxiaoConfigurationException::REASON_ORG_NOT_ALLOWED,
                );
            }
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function normalizeOrgAllowlist(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new YunxiaoConfigurationException('org_allowlist 必须是组织 ID 数组');
        }

        $ids = [];

        foreach ($value as $id) {
            if (! is_scalar($id)) {
                throw new YunxiaoConfigurationException('org_allowlist 只能包含组织 ID 字符串');
            }

            $id = trim((string) $id);

            if ($id === '') {
                continue;
            }

            if (mb_strlen($id) > 100) {
                throw new YunxiaoConfigurationException('org_allowlist 中的组织 ID 不能超过 100 字符');
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function normalizeScope(mixed $scope): string
    {
        $normalized = ucfirst(strtolower(trim((string) $scope)));

        if (! in_array($normalized, [
            ProjexYunxiaoClient::SCOPE_REGION,
            ProjexYunxiaoClient::SCOPE_CENTRAL,
            ProjexYunxiaoClient::SCOPE_ACCOUNT,
        ], true)) {
            throw new YunxiaoConfigurationException('scope 仅支持 Region|Central|Account');
        }

        return $normalized;
    }

    /**
     * 连接 base_url：显式值优先；否则按 scope 取默认接入点。
     *
     * 更新连接且未显式改地址时，当前地址是已知默认接入点（或为空）才跟随 scope 切换，
     * 避免 Region ⇄ Central 后继续打旧域名；自定义代理域名保持不动。
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveBaseUrl(mixed $provided, array $config, ?IntegrationConnection $existing = null): string
    {
        $provided = is_string($provided) ? trim($provided) : '';

        if ($provided !== '') {
            return $this->urlGuard->assertSafe($provided);
        }

        $current = $existing !== null ? trim((string) ($existing->base_url ?? '')) : '';

        if ($current !== '' && ! $this->isDefaultBaseUrl($current)) {
            return $this->urlGuard->assertSafe($current);
        }

        return $this->urlGuard->assertSafe($this->defaultBaseUrlFor((string) ($config['scope'] ?? ProjexYunxiaoClient::SCOPE_REGION)));
    }

    private function defaultBaseUrlFor(string $scope): string
    {
        $central = in_array($scope, [ProjexYunxiaoClient::SCOPE_CENTRAL, ProjexYunxiaoClient::SCOPE_ACCOUNT], true);

        return $central
            ? (string) config('yunxiao.api.central_base_url', 'https://openapi-rdc.aliyuncs.com')
            : (string) config('yunxiao.api.base_url', 'https://devops.aliyuncs.com');
    }

    private function isDefaultBaseUrl(string $url): bool
    {
        $normalized = rtrim(strtolower(trim($url)), '/');

        foreach ([
            ProjexYunxiaoClient::SCOPE_REGION,
            ProjexYunxiaoClient::SCOPE_CENTRAL,
            ProjexYunxiaoClient::SCOPE_ACCOUNT,
        ] as $scope) {
            if ($normalized === rtrim(strtolower($this->defaultBaseUrlFor($scope)), '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeExpiry(string $value): string
    {
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            throw new YunxiaoConfigurationException('token_expires_at 不是合法时间');
        }
    }

    /**
     * 保存前探测：按 scope 验证 token 与最小读取权限。
     *
     * Region / Central：列项目 + 模板（组织/模板读取）；
     * Account：列组织 + 当前用户（账号级只读），不要求 default_org_id 已配置。
     */
    private function probe(IntegrationConnection $probe): void
    {
        if (ProjexYunxiaoClient::isAccount($probe)) {
            $this->accountClient->listOrganizations($probe);
            $this->accountClient->currentUser($probe);

            return;
        }

        $this->client->listProjects($probe, ['page' => 1]);
        $this->client->listTemplates($probe);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function probeModel(int $tenantId, string $name, string $baseUrl, string $token, array $config): IntegrationConnection
    {
        return new IntegrationConnection([
            'tenant_id' => $tenantId,
            'name' => $name,
            'provider' => self::PROVIDER,
            'base_url' => $baseUrl,
            'auth_type' => IntegrationConnection::AUTH_BEARER,
            'credentials' => ['token' => $token],
            'config' => $config,
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);
    }

    /**
     * 统一脱敏错误摘要：不含 token/请求头/原始响应体。
     */
    private function safeError(\Throwable $e): string
    {
        if ($e instanceof YunxiaoException) {
            return mb_substr($e->getMessage(), 0, 500);
        }

        return '云效连接检查失败';
    }
}
