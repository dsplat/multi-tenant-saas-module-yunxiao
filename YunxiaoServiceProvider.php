<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao;

use MultiTenantSaas\Access\ResourceRegistry;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\AuthorizationCapabilityRegistryContract;
use MultiTenantSaas\Contracts\CapabilityRequirementResolverContract;
use MultiTenantSaas\Contracts\SceneRegistryContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Yunxiao\Console\Commands\RetryWritebacksCommand;
use MultiTenantSaas\Modules\Yunxiao\Console\Commands\SyncWorkitemsCommand;
use MultiTenantSaas\Modules\Yunxiao\Console\Commands\VerifyTemplateCommand;
use MultiTenantSaas\Modules\Yunxiao\Contracts\YunxiaoClientContract;
use MultiTenantSaas\Modules\Yunxiao\Services\RegionYunxiaoClient;
use MultiTenantSaas\Modules\Yunxiao\Services\Tools\YunxiaoProgressSummaryHandler;
use MultiTenantSaas\Modules\Yunxiao\Services\Tools\YunxiaoWorkitemQueryHandler;
use MultiTenantSaas\Modules\Yunxiao\Services\Tools\YunxiaoWritebackCommentDraftHandler;
use MultiTenantSaas\Modules\Yunxiao\Support\YunxiaoUrlGuard;

/**
 * 云效集成（Region OpenAPI 连接器）
 *
 * 路由 / 迁移 / 视图 / 翻译由基类 ModuleServiceProvider 自动加载；
 * 这里只做绑定、能力点、资源保护声明、AI 工具、场景与命令注册。
 */
class YunxiaoServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'yunxiao';

    /** @var array<string, array{0:string,1:string}> 能力点 => [权限后缀, 说明] */
    private const CAPABILITIES = [
        'connection.view' => ['view', '查看连接（脱敏）'],
        'connection.manage' => ['manage', '创建/更新/测试/轮换连接'],
        'project.view' => ['view', '查看项目投影/映射'],
        'project.create' => ['manage', 'H1 建项目'],
        'workitem.view' => ['view', '查看工作项投影'],
        'workitem.sync' => ['manage', '手动触发同步'],
        'writeback.view' => ['view', '查看写回记录'],
        'writeback.retry' => ['manage', '重试/死信处理'],
        'template_map.manage' => ['manage', '模板/状态映射'],
        // P1：仅注册能力点，P0 不提供成员同步端点
        'member.sync' => ['manage', '成员同步（P1）'],
    ];

    /** 旧脚手架能力点兼容别名：list/read → view，create/update/delete → manage */
    private const COMPAT_ALIASES = [
        'list' => 'view',
        'read' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
    ];

    protected function registerModuleBindings(): void
    {
        $this->app->bind(YunxiaoClientContract::class, RegionYunxiaoClient::class);
        $this->app->singleton(YunxiaoUrlGuard::class);
    }

    protected function bootModule(): void
    {
        $this->registerAuthorizationCapabilities();
        $this->registerResources();
        $this->registerTools();
        $this->registerScenes();
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWorkitemsCommand::class,
                RetryWritebacksCommand::class,
                VerifyTemplateCommand::class,
            ]);
        }
    }

    private function registerAuthorizationCapabilities(): void
    {
        $registry = $this->app->make(AuthorizationCapabilityRegistryContract::class);
        $requirements = $this->app->make(CapabilityRequirementResolverContract::class);

        foreach (self::CAPABILITIES as $suffix => [$permission, $description]) {
            $this->registerCapability(
                $registry,
                'common.yunxiao.' . $suffix,
            );
        }

        foreach (self::COMPAT_ALIASES as $action => $permission) {
            $this->registerCapability(
                $registry,
                'common.yunxiao.' . $action,
            );
        }

        // 权限映射保持字面量：AdminConsolePermissionMapTest 静态解析 capability→permission。
        $requirements->register('common.yunxiao.connection.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.connection.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.project.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.project.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.workitem.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.workitem.sync', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.writeback.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.writeback.retry', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.template_map.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.member.sync', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.list', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.read', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.view']);
        $requirements->register('common.yunxiao.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.update', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
        $requirements->register('common.yunxiao.delete', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'yunxiao.manage']);
    }

    private function registerCapability(
        AuthorizationCapabilityRegistryContract $registry,
        string $key,
    ): void {
        $registry->register(new CapabilityDefinition(
            key: $key,
            subjectTypes: [ExecutionIdentity::TYPE_OPERATOR],
            targetScope: CapabilityDefinition::TARGET_TENANT,
            admissionPolicy: [ExecutionIdentity::TYPE_OPERATOR => ['member']],
        ));
    }

    /**
     * 受保护资源声明：租户隔离 + 需策略 + 敏感；过滤列仅供数据访问门校验。
     */
    private function registerResources(): void
    {
        $registry = $this->app->make(ResourceRegistry::class);

        $registry->register(
            name: 'yunxiao_projects',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: ['project_id', 'external_project_id', 'status', 'name'],
            returnableColumns: [
                'yunxiao_project_id', 'connection_id', 'project_id', 'external_project_id',
                'name', 'custom_code', 'template_id', 'scope', 'status', 'last_synced_at',
            ],
            supportedActions: ['read', 'aggregate'],
        );

        $registry->register(
            name: 'yunxiao_workitems',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'project_id', 'yunxiao_project_id', 'external_project_id',
                'external_workitem_id', 'serial_number', 'status_id', 'fetched_at',
            ],
            returnableColumns: [
                'yunxiao_workitem_id', 'connection_id', 'yunxiao_project_id',
                'external_workitem_id', 'serial_number', 'workitem_type_id', 'subject',
                'status_id', 'status_name', 'assigned_to', 'gmt_created', 'gmt_modified',
                'fetched_at',
            ],
            supportedActions: ['read', 'aggregate'],
        );

        $registry->register(
            name: 'yunxiao_template_maps',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: ['project_type', 'template_id', 'is_default'],
            returnableColumns: [
                'template_map_id', 'project_type', 'template_id', 'workitem_type_map',
                'status_map', 'is_default', 'metadata',
            ],
            supportedActions: ['read'],
        );
    }

    private function registerTools(): void
    {
        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'yunxiao_workitem_query',
            name: '查询云效工作项',
            description: '按任务号/项目/状态查询云效工作项只读投影（不含完整描述正文，也不编辑）',
            handlerClass: YunxiaoWorkitemQueryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'serial_number' => ['type' => 'string', 'description' => '任务号（如 TASK-123）'],
                    'project_id' => ['type' => 'integer', 'description' => '宿主项目 ID'],
                    'yunxiao_project_id' => ['type' => 'integer', 'description' => '云效项目投影 ID'],
                    'status_id' => ['type' => 'string', 'description' => '云效状态 ID'],
                    'keyword' => ['type' => 'string', 'description' => '标题/任务号关键词'],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                'required' => [],
            ],
            category: 'yunxiao',
            risk: 'L1',
            audience: 'operator',
            requiredCapability: 'common.yunxiao.workitem.view',
            dataResource: 'yunxiao_workitems',
            dataAction: 'read',
        );

        $registry->register(
            slug: 'yunxiao_progress_summary',
            name: '云效项目进度汇总',
            description: '按项目汇总工作项状态数量与未映射状态（仅聚合，不含成员隐私字段）',
            handlerClass: YunxiaoProgressSummaryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'project_id' => ['type' => 'integer', 'description' => '宿主项目 ID'],
                    'yunxiao_project_id' => ['type' => 'integer', 'description' => '云效项目投影 ID'],
                ],
                'required' => [],
            ],
            category: 'yunxiao',
            risk: 'L1',
            audience: 'operator',
            requiredCapability: 'common.yunxiao.project.view',
            dataResource: 'yunxiao_workitems',
            dataAction: 'aggregate',
        );

        $registry->register(
            slug: 'yunxiao_writeback_comment_draft',
            name: '起草云效写回评论',
            description: '生成模板化写回评论草稿（只起草，不入队、不提交）',
            handlerClass: YunxiaoWritebackCommentDraftHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['mr', 'pipeline']],
                    'action' => ['type' => 'string', 'description' => 'GitLab 动作（如 merged）'],
                    'summary' => ['type' => 'string', 'description' => '事件摘要'],
                    'url' => ['type' => 'string', 'description' => 'GitLab 链接'],
                    'event_type' => ['type' => 'string', 'description' => '状态映射事件键（可选）'],
                ],
                'required' => [],
            ],
            category: 'yunxiao',
            risk: 'L1',
            audience: 'operator',
            requiredCapability: 'common.yunxiao.writeback.view',
            dataResource: 'yunxiao_template_maps',
            dataAction: 'read',
        );
    }

    private function registerScenes(): void
    {
        if (! $this->app->bound(SceneRegistryContract::class)) {
            return;
        }

        $this->app->make(SceneRegistryContract::class)
            ->registerScene('yunxiao', '云效集成', 'Menu', '云效集成管理', 100);
    }
}
