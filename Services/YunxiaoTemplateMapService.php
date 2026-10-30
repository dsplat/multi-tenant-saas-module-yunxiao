<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoTemplateMap;

/**
 * 模板/状态映射服务（fail closed）
 *
 * - 无精确映射且无显式 is_default → 明确配置错误，不静默套默认模板；
 * - 保存时校验模板/工作项类型/状态 ID 来自云效真实工作流；
 * - 映射变更只影响新项目/新写回，在途项目不自动迁移。
 */
class YunxiaoTemplateMapService
{
    public function __construct(
        private readonly YunxiaoClientContract $client,
        private readonly YunxiaoConnectionService $connections,
    ) {}

    /**
     * @return list<YunxiaoTemplateMap>
     */
    public function list(int $tenantId): array
    {
        return TenantContext::runInTenant($tenantId, fn (): array => YunxiaoTemplateMap::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('project_type')
            ->orderBy('template_map_id')
            ->get()
            ->all());
    }

    public function find(int $tenantId, int $templateMapId): YunxiaoTemplateMap
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $templateMapId): YunxiaoTemplateMap {
            $map = YunxiaoTemplateMap::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($templateMapId)
                ->first();

            if (! $map instanceof YunxiaoTemplateMap) {
                throw new YunxiaoConfigurationException('模板映射不存在');
            }

            return $map;
        });
    }

    /**
     * 按宿主项目类型解析映射：精确匹配优先，其次显式 is_default。
     */
    public function resolve(int $tenantId, string $projectType): YunxiaoTemplateMap
    {
        if (trim($projectType) === '') {
            throw new YunxiaoConfigurationException('项目类型不能为空');
        }

        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $projectType): YunxiaoTemplateMap {
            $map = YunxiaoTemplateMap::query()
                ->where('tenant_id', $tenantId)
                ->where('project_type', $projectType)
                ->first();

            if ($map instanceof YunxiaoTemplateMap) {
                return $map;
            }

            $map = YunxiaoTemplateMap::query()
                ->where('tenant_id', $tenantId)
                ->where('is_default', true)
                ->orderBy('template_map_id')
                ->first();

            if ($map instanceof YunxiaoTemplateMap) {
                return $map;
            }

            throw YunxiaoConfigurationException::missingTemplateMap($projectType);
        });
    }

    /**
     * 保存映射（按 tenant + project_type 唯一），保存前校验真实工作流。
     *
     * @param  array{project_type:string, template_id:string, workitem_type_map?:array<string,string>, status_map?:array<string,string>, is_default?:bool, metadata?:array<string,mixed>}  $data
     */
    public function upsert(int $tenantId, array $data, ?int $connectionId = null): YunxiaoTemplateMap
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $data, $connectionId): YunxiaoTemplateMap {
            $projectType = trim((string) ($data['project_type'] ?? ''));
            $templateId = trim((string) ($data['template_id'] ?? ''));

            if ($projectType === '' || $templateId === '') {
                throw new YunxiaoConfigurationException('project_type 与 template_id 均不能为空');
            }

            $connection = $this->connections->activeConnection($tenantId, $connectionId);
            $templates = $this->client->listTemplates($connection);
            $template = $this->findTemplate($templates, $templateId);

            $workitemTypeMap = $this->stringMap((array) ($data['workitem_type_map'] ?? []));
            $statusMap = $this->stringMap((array) ($data['status_map'] ?? []));

            $this->assertWorkitemTypes($template, $workitemTypeMap);
            $this->assertStatuses($template, $statusMap);

            $existing = YunxiaoTemplateMap::query()
                ->where('tenant_id', $tenantId)
                ->where('project_type', $projectType)
                ->first();

            $metadata = array_merge(
                (array) ($existing?->metadata ?? []),
                (array) ($data['metadata'] ?? []),
                [
                    'known_workitem_type_ids' => array_values(array_column($template['workitem_types'], 'id')),
                    'known_status_ids' => array_values(array_column($template['statuses'], 'id')),
                    'validated_at' => now()->toIso8601String(),
                ],
            );

            $map = YunxiaoTemplateMap::updateOrCreate(
                ['tenant_id' => $tenantId, 'project_type' => $projectType],
                [
                    'template_id' => $templateId,
                    'workitem_type_map' => $workitemTypeMap,
                    'status_map' => $statusMap,
                    'is_default' => (bool) ($data['is_default'] ?? false),
                    'metadata' => $metadata,
                ],
            );

            if ((bool) $map->is_default) {
                YunxiaoTemplateMap::query()
                    ->where('tenant_id', $tenantId)
                    ->where('template_map_id', '!=', $map->template_map_id)
                    ->update(['is_default' => false]);
            }

            return $map->refresh();
        });
    }

    /**
     * 校验全部映射（不写库）。
     *
     * @return list<array{template_map_id:int, project_type:string, template_id:string, ok:bool, error:string|null}>
     */
    public function verify(int $tenantId, ?int $connectionId = null): array
    {
        $connection = null;
        $connectionError = null;

        try {
            $connection = $this->connections->activeConnection($tenantId, $connectionId);
            $templates = $this->client->listTemplates($connection);
        } catch (\Throwable $e) {
            $connectionError = $e->getMessage();
            $templates = [];
        }

        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $templates, $connectionError): array {
            $results = [];

            foreach ($this->list($tenantId) as $map) {
                if ($connectionError !== null) {
                    $results[] = [
                        'template_map_id' => (int) $map->template_map_id,
                        'project_type' => $map->project_type,
                        'template_id' => $map->template_id,
                        'ok' => false,
                        'error' => $connectionError,
                    ];

                    continue;
                }

                try {
                    $template = $this->findTemplate($templates, (string) $map->template_id);
                    $this->assertWorkitemTypes($template, (array) $map->workitem_type_map);
                    $this->assertStatuses($template, (array) $map->status_map);

                    $results[] = [
                        'template_map_id' => (int) $map->template_map_id,
                        'project_type' => $map->project_type,
                        'template_id' => $map->template_id,
                        'ok' => true,
                        'error' => null,
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'template_map_id' => (int) $map->template_map_id,
                        'project_type' => $map->project_type,
                        'template_id' => $map->template_id,
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * 某个云效项目（按 template_id 匹配映射）的已知状态集合；无映射返回空（未知状态不猜测）。
     *
     * @return list<string>
     */
    public function knownStatusIdsForProject(int $tenantId, YunxiaoProject $project): array
    {
        $map = TenantContext::runInTenant($tenantId, fn (): ?YunxiaoTemplateMap => YunxiaoTemplateMap::query()
            ->where('tenant_id', $tenantId)
            ->where('template_id', $project->template_id)
            ->orderBy('is_default')
            ->orderBy('template_map_id')
            ->first());

        if (! $map instanceof YunxiaoTemplateMap) {
            return [];
        }

        return $map->knownStatusIds();
    }

    /**
     * 写回时按模板 ID 取状态映射（平台事件 => 云效状态 ID）。
     *
     * @return array<string, string>
     */
    public function statusMapForProject(int $tenantId, YunxiaoProject $project): array
    {
        $map = TenantContext::runInTenant($tenantId, fn (): ?YunxiaoTemplateMap => YunxiaoTemplateMap::query()
            ->where('tenant_id', $tenantId)
            ->where('template_id', $project->template_id)
            ->orderBy('is_default')
            ->orderBy('template_map_id')
            ->first());

        if (! $map instanceof YunxiaoTemplateMap) {
            return [];
        }

        return $this->stringMap((array) $map->status_map);
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     * @return array<string, mixed>
     */
    private function findTemplate(array $templates, string $templateId): array
    {
        foreach ($templates as $template) {
            if ((string) ($template['template_id'] ?? '') === $templateId) {
                return $template;
            }
        }

        throw new YunxiaoConfigurationException(
            sprintf('云效模板 %s 不存在或当前 token 无权读取', $templateId),
            YunxiaoConfigurationException::REASON_TEMPLATE_NOT_FOUND,
        );
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $map
     */
    private function assertWorkitemTypes(array $template, array $map): void
    {
        $known = array_map('strval', array_column((array) ($template['workitem_types'] ?? []), 'id'));

        foreach ($map as $hostType => $yunxiaoTypeId) {
            if ($yunxiaoTypeId === '') {
                throw new YunxiaoConfigurationException(
                    sprintf('工作项类型映射 %s 缺少云效类型 ID', $hostType),
                    YunxiaoConfigurationException::REASON_INVALID_WORKITEM_TYPE,
                );
            }

            if (! in_array($yunxiaoTypeId, $known, true)) {
                throw new YunxiaoConfigurationException(
                    sprintf('云效工作项类型 %s 不属于模板 %s 的真实工作流', $yunxiaoTypeId, (string) ($template['template_id'] ?? '')),
                    YunxiaoConfigurationException::REASON_INVALID_WORKITEM_TYPE,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $map
     */
    private function assertStatuses(array $template, array $map): void
    {
        $known = array_map('strval', array_column((array) ($template['statuses'] ?? []), 'id'));

        foreach ($map as $event => $statusId) {
            if ($statusId === '') {
                throw new YunxiaoConfigurationException(
                    sprintf('状态映射 %s 缺少云效状态 ID', $event),
                    YunxiaoConfigurationException::REASON_INVALID_STATUS,
                );
            }

            if (! in_array($statusId, $known, true)) {
                throw new YunxiaoConfigurationException(
                    sprintf('云效状态 %s 不属于模板 %s 的真实工作流', $statusId, (string) ($template['template_id'] ?? '')),
                    YunxiaoConfigurationException::REASON_INVALID_STATUS,
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $map
     * @return array<string, string>
     */
    private function stringMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || $value === null) {
                continue;
            }

            $normalized[$key] = trim((string) $value);
        }

        return $normalized;
    }
}
