<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemSyncService;

/**
 * 手动同步工作项投影（按项目/updatedSince 增量）
 */
class SyncWorkitemsCommand extends Command
{
    protected $signature = 'yunxiao:sync-workitems
        {--tenant= : 租户 ID（必填）}
        {--project= : 宿主项目 ID（可选，缺省同步全部 active/partial 项目）}
        {--dry-run : 只统计不落库、不派发事件、不推进游标}';

    protected $description = '同步云效工作项投影（增量，游标落 Integration）';

    public function handle(YunxiaoWorkitemSyncService $service): int
    {
        $tenantId = trim((string) $this->option('tenant'));

        if ($tenantId === '' || ! ctype_digit($tenantId)) {
            $this->error('--tenant 必须是正整数租户 ID');

            return self::FAILURE;
        }

        $project = $this->option('project');
        $filters = [];

        if ($project !== null && $project !== '') {
            if (! ctype_digit((string) $project)) {
                $this->error('--project 必须是宿主项目 ID');

                return self::FAILURE;
            }

            $filters['project_id'] = (int) $project;
        }

        try {
            $stats = TenantContext::runInTenant((int) $tenantId, fn (): array => $service->sync((int) $tenantId, $filters + [
                'dry_run' => (bool) $this->option('dry-run'),
            ]));
        } catch (\Throwable $e) {
            $this->error('同步失败：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['指标', '值'], [
            ['模式', $stats['dry_run'] ? 'dry-run' : 'write'],
            ['项目数', $stats['projects']],
            ['页数', $stats['pages']],
            ['读取', $stats['fetched']],
            ['新建', $stats['created']],
            ['更新', $stats['updated']],
            ['失败', $stats['failed']],
            ['部分失败', $stats['partial'] ? 'yes' : 'no'],
            ['游标', $stats['cursor'] ?? '-'],
        ]);

        foreach ((array) $stats['errors'] as $error) {
            $this->warn((string) $error);
        }

        if ($stats['failed'] > 0 && ! $stats['dry_run']) {
            $this->warn('存在失败工作项：已成功页未回滚，游标仅推进到成功页最大更新时间');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
