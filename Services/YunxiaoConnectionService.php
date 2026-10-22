<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Services\IntegrationConnectionService;
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
            if ($connection->status === IntegrationConnection::STATUS_ACTIVE) {
                return $connection;
            }
        }

        throw new YunxiaoConfigurationException('租户尚未配置云效连接', YunxiaoConfigurationException::REASON_CONNECTION_NOT_FOUND);
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

            $baseUrl = $this->urlGuard->assertSafe((string) ($data['base_url'] ?? config('yunxiao.api.base_url')));
            $config = $this->normalizeConfig((array) ($data['config'] ?? []), []);
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
     * 更新连接；仅当 token/base_url/auth_style 变更时重新测试。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int $connectionId, array $data): IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $data): IntegrationConnection {
            $connection = $this->find($tenantId, $connectionId);
            $baseUrl = array_key_exists('base_url', $data) && $data['base_url'] !== null
                ? $this->urlGuard->assertSafe((string) $data['base_url'])
                : $connection->base_url;
            $config = $this->normalizeConfig((array) ($data['config'] ?? []), (array) ($connection->config ?? []));
            $token = isset($data['token']) && $data['token'] !== '' ? (string) $data['token'] : null;
            $authStyleChanged = isset($data['config']['auth_style'])
                && (string) $data['config']['auth_style'] !== (string) ($connection->config['auth_style'] ?? 'token');

            if ($token !== null || $baseUrl !== $connection->base_url || $authStyleChanged) {
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

            if (array_key_exists('base_url', $data)) {
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

            $probe = $this->probeModel(
                $tenantId,
                (string) $connection->name,
                (string) ($connection->base_url ?: config('yunxiao.api.base_url')),
                $token,
                $config,
            );
            $this->probe($probe);

            $updated = $this->connections->update($tenantId, $connectionId, [
                'credentials' => array_merge((array) ($connection->credentials ?? []), ['token' => $token]),
                'config' => $config,
            ]);
            $this->connections->markChecked($tenantId, $connectionId);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, true, null));

            return $updated;
        });
    }

    /**
     * 连通性/权限测试（列项目 + 模板），结果落 last_checked_*。
     *
     * @return array{ok:bool, projects:int, templates:int, error:string|null}
     */
    public function test(int $tenantId, int $connectionId): array
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId): array {
            $connection = $this->find($tenantId, $connectionId);

            try {
                $projects = $this->client->listProjects($connection, ['page' => 1]);
                $templates = $this->client->listTemplates($connection);
            } catch (\Throwable $e) {
                $error = $this->safeError($e);
                $this->connections->markChecked($tenantId, $connectionId, $error);
                Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, false, $error));

                return ['ok' => false, 'projects' => 0, 'templates' => 0, 'error' => $error];
            }

            $this->connections->markChecked($tenantId, $connectionId, null);
            Event::dispatch(new YunxiaoConnectionChecked($tenantId, $connectionId, true, null));

            return ['ok' => true, 'projects' => count($projects), 'templates' => count($templates), 'error' => null];
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

        $scope = $input['scope'] ?? $existing['scope'] ?? 'Region';

        if (! in_array($scope, ['Region'], true)) {
            throw new YunxiaoConfigurationException('P0 仅支持 Region 组织（Central 为 P1）');
        }

        $config['scope'] = 'Region';

        foreach (['org_id', 'org_name', 'region', 'default_template_id'] as $key) {
            if (array_key_exists($key, $input)) {
                $config[$key] = $input[$key] !== null ? (string) $input[$key] : null;
            }
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

        return $config;
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
     * 保存前探测：列项目 + 列模板（同时验证 token/组织/模板读取权限）。
     */
    private function probe(IntegrationConnection $probe): void
    {
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
