<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWritebackService;

/**
 * 重试云效写回死信（重置后立即投递一次）
 */
class RetryWritebacksCommand extends Command
{
    protected $signature = 'yunxiao:retry-writebacks
        {--tenant= : 租户 ID（必填）}
        {--dry-run : 只统计死信数量，不重置、不投递}';

    protected $description = '重试云效写回死信（Integration outbox）';

    public function handle(YunxiaoWritebackService $service): int
    {
        $tenantId = trim((string) $this->option('tenant'));

        if ($tenantId === '' || ! ctype_digit($tenantId)) {
            $this->error('--tenant 必须是正整数租户 ID');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = TenantContext::runInTenant(
                (int) $tenantId,
                fn (): array => $service->retryAll((int) $tenantId, null, $dryRun),
            );

            if ($dryRun) {
                $this->info(sprintf('dry-run：待重试死信 %d 条', $result['retried']));

                return self::SUCCESS;
            }

            $this->info(sprintf('已重置死信 %d 条，开始投递', $result['retried']));
            $stats = $service->process((int) $tenantId);
        } catch (\Throwable $e) {
            $this->error('重试失败：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['指标', '值'], [
            ['连接数', $stats['connections']],
            ['认领', $stats['claimed']],
            ['成功', $stats['sent']],
            ['失败（可重试）', $stats['failed']],
            ['死信', $stats['dead']],
        ]);

        return $stats['failed'] === 0 && $stats['dead'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
