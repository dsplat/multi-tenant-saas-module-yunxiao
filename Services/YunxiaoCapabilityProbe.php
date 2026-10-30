<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use Throwable;

/**
 * 云效连接能力探针（E4：read / sync / write 分项）。
 *
 * 与「一个 connected=true 覆盖所有能力」不同，本探针逐项返回
 * `{capability, status, detail}`，单项异常不会波及其它能力：
 * - read：listProjects 首页（可达性 + token/组织认证）；
 * - sync：listWorkitems(page=1, per_page=1)（搜索/分页契约）；
 * - write：固定 skipped / g3_not_authorized，绝不发起写请求（夜间授权约束）。
 *
 * 安全：
 * - 结果显式带 provider / tenant_id / connection_id / checked_at / writes_performed；
 * - 失败 detail 只写脱敏 reason，不返回异常 message、token 或响应体；
 * - 当前租户上下文与连接 tenant_id 不一致时直接拒绝，不调用上游。
 *
 * `config.probe_project_id`（或 `config.default_project_id`）可选，用于给 sync
 * 指定项目；缺省时取 listProjects 首页的第一项（仍受 sync 自己的 try/catch 保护）。
 */
class YunxiaoCapabilityProbe
{
    public const CAPABILITY_READ = 'read';

    public const CAPABILITY_SYNC = 'sync';

    public const CAPABILITY_WRITE = 'write';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const DETAIL_WRITE_SKIPPED = 'g3_not_authorized';

    public const DETAIL_TENANT_MISMATCH = 'tenant_mismatch';

    public function __construct(
        private readonly YunxiaoClientContract $client,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     tenant_id: int,
     *     connection_id: int,
     *     checked_at: string,
     *     writes_performed: bool,
     *     capabilities: list<array{capability: string, status: string, detail: string}>
     * }
     */
    public function probe(IntegrationConnection $connection): array
    {
        if ($this->tenantMismatch($connection)) {
            return $this->envelope($connection, [
                $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, self::DETAIL_TENANT_MISMATCH),
                $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, self::DETAIL_TENANT_MISMATCH),
                $this->writeSkipped(),
            ]);
        }

        return $this->envelope($connection, [
            $this->probeRead($connection),
            $this->probeSync($connection),
            $this->writeSkipped(),
        ]);
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function probeRead(IntegrationConnection $connection): array
    {
        try {
            $projects = $this->client->listProjects($connection, ['page' => 1, 'per_page' => 1]);

            foreach ($projects as $project) {
                if (! is_array($project)) {
                    return $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, 'invalid_response');
                }
            }

            return $this->item(self::CAPABILITY_READ, self::STATUS_OK, 'projects_listed');
        } catch (YunxiaoApiException|YunxiaoConfigurationException $e) {
            return $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, $e->reason);
        } catch (Throwable) {
            return $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, 'probe_failed');
        }
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function probeSync(IntegrationConnection $connection): array
    {
        try {
            $projectId = $this->syncProjectId($connection);

            if ($projectId === null) {
                return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, 'project_unavailable');
            }

            $page = $this->client->listWorkitems($connection, $projectId, ['page' => 1, 'per_page' => 1]);

            if (! isset($page['items']) || ! is_array($page['items']) || ! array_key_exists('has_more', $page)) {
                return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, 'invalid_response');
            }

            return $this->item(self::CAPABILITY_SYNC, self::STATUS_OK, 'workitems_page_ok');
        } catch (YunxiaoApiException|YunxiaoConfigurationException $e) {
            return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, $e->reason);
        } catch (Throwable) {
            return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, 'probe_failed');
        }
    }

    private function syncProjectId(IntegrationConnection $connection): ?string
    {
        $config = (array) ($connection->config ?? []);

        foreach (['probe_project_id', 'default_project_id'] as $key) {
            $value = $config[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $projects = $this->client->listProjects($connection, ['page' => 1, 'per_page' => 1]);

        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }

            $externalId = trim((string) ($project['external_project_id'] ?? ''));

            if ($externalId !== '') {
                return $externalId;
            }
        }

        return null;
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function writeSkipped(): array
    {
        return $this->item(self::CAPABILITY_WRITE, self::STATUS_SKIPPED, self::DETAIL_WRITE_SKIPPED);
    }

    /**
     * 连接所属租户与当前上下文不一致时 fail closed；无上下文（CLI/显式调用）
     * 不猜测默认租户，结果仍限定在传入连接。
     */
    private function tenantMismatch(IntegrationConnection $connection): bool
    {
        $contextTenantId = TenantContext::getId();

        if ($contextTenantId === null || $contextTenantId === '') {
            return false;
        }

        return (string) ($connection->tenant_id ?? '') !== $contextTenantId;
    }

    /**
     * @param  list<array{capability: string, status: string, detail: string}>  $capabilities
     * @return array{
     *     provider: string,
     *     tenant_id: int,
     *     connection_id: int,
     *     checked_at: string,
     *     writes_performed: bool,
     *     capabilities: list<array{capability: string, status: string, detail: string}>
     * }
     */
    private function envelope(IntegrationConnection $connection, array $capabilities): array
    {
        return [
            'provider' => 'yunxiao',
            'tenant_id' => (int) ($connection->tenant_id ?? 0),
            'connection_id' => (int) ($connection->connection_id ?? 0),
            'checked_at' => now()->toIso8601String(),
            'writes_performed' => false,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function item(string $capability, string $status, string $detail): array
    {
        return [
            'capability' => $capability,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
