<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoFlowClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoApiException;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Support\YunxiaoUrlGuard;

/**
 * 云效 Flow 只读客户端（Region / Central 双站点）。
 *
 * token 只在请求头；不跟随外部重定向；非 2xx 抛 YunxiaoApiException。
 */
final class YunxiaoFlowClient implements YunxiaoFlowClientContract
{
    public function __construct(
        private readonly Factory $http,
        private readonly YunxiaoUrlGuard $guard,
    ) {}

    public function listPipelines(IntegrationConnection $connection, array $query = []): array
    {
        return $this->request($connection, $this->flowPrefix($connection).'/pipelines', $query);
    }

    public function listPipelineRuns(IntegrationConnection $connection, string $pipelineId, array $query = []): array
    {
        return $this->request(
            $connection,
            $this->flowPrefix($connection).'/pipelines/'.rawurlencode($pipelineId).'/runs',
            $query,
        );
    }

    public function getPipelineRun(IntegrationConnection $connection, string $pipelineId, string $runId): array
    {
        return $this->request(
            $connection,
            $this->flowPrefix($connection).'/pipelines/'.rawurlencode($pipelineId).'/runs/'.rawurlencode($runId),
            [],
        );
    }

    private function flowPrefix(IntegrationConnection $connection): string
    {
        if (ProjexYunxiaoClient::isCentral($connection) || ProjexYunxiaoClient::isAccount($connection)) {
            $config = (array) ($connection->config ?? []);
            $orgId = trim((string) ($config['org_id'] ?? $config['default_org_id'] ?? ''));

            if ($orgId === '') {
                throw new YunxiaoConfigurationException(
                    'Central/Account 连接缺少组织 ID（config.org_id 或 config.default_org_id）',
                    YunxiaoConfigurationException::REASON_ORG_MISSING,
                );
            }

            return '/oapi/v1/flow/organizations/'.rawurlencode($orgId);
        }

        return '/oapi/v1/flow';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function request(IntegrationConnection $connection, string $path, array $query): array
    {
        $baseUrl = trim((string) ($connection->base_url ?? '')) ?: $this->defaultBaseUrl($connection);
        $baseUrl = $this->guard->assertSafe($baseUrl);

        $token = (string) (((array) ($connection->credentials ?? []))['token'] ?? '');

        if ($token === '') {
            throw new YunxiaoConfigurationException(
                '云效连接缺少访问令牌（credentials.token）',
                YunxiaoConfigurationException::REASON_TOKEN_MISSING,
            );
        }

        $config = (array) ($connection->config ?? []);
        $headers = ['Accept' => 'application/json'];
        $authStyle = (string) ($config['auth_style'] ?? config('yunxiao.api.auth_style', 'token'));

        if ($authStyle === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$token;
        } else {
            $headers['x-yunxiao-token'] = $token;
        }

        try {
            $response = $this->http
                ->timeout((int) ($config['timeout'] ?? config('yunxiao.api.timeout', 10)))
                ->withHeaders($headers)
                ->get($baseUrl.$path, $query);
        } catch (ConnectionException) {
            throw new YunxiaoApiException('云效 Flow API 不可达（网络连接失败）', 503, YunxiaoApiException::REASON_UNAVAILABLE, true);
        }

        if ($response->failed()) {
            throw YunxiaoApiException::fromHttpStatus($response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new YunxiaoApiException('云效 Flow API 返回内容无法解析', 502, YunxiaoApiException::REASON_UNKNOWN);
        }

        return $json;
    }

    private function defaultBaseUrl(IntegrationConnection $connection): string
    {
        $central = ProjexYunxiaoClient::isCentral($connection) || ProjexYunxiaoClient::isAccount($connection);

        return $central
            ? (string) config('yunxiao.api.central_base_url', 'https://openapi-rdc.aliyuncs.com')
            : (string) config('yunxiao.api.base_url', 'https://devops.aliyuncs.com');
    }
}
