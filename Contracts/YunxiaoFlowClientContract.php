<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Contracts;

use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 云效 Flow 只读客户端（流水线 / 运行实例）。
 *
 * 依据云效 OpenAPI：
 * - Region: `/oapi/v1/flow/pipelines`、`/oapi/v1/flow/pipelines/{id}/runs`
 * - Central: `/oapi/v1/flow/organizations/{organizationId}/pipelines/...`
 */
interface YunxiaoFlowClientContract
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listPipelines(IntegrationConnection $connection, array $query = []): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listPipelineRuns(IntegrationConnection $connection, string $pipelineId, array $query = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getPipelineRun(IntegrationConnection $connection, string $pipelineId, string $runId): array;
}
