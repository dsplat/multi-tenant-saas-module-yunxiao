<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoTemplateMapService;

/**
 * 校验模板/状态映射仍属于云效真实工作流（只读）
 */
class VerifyTemplateCommand extends Command
{
    protected $signature = 'yunxiao:verify-template
        {--tenant= : 租户 ID（必填）}
        {--dry-run : 仅展示校验结果（本命令本身不写库）}';

    protected $description = '校验云效模板映射（模板/工作项类型/状态 ID）';

    public function handle(YunxiaoTemplateMapService $service): int
    {
        $tenantId = trim((string) $this->option('tenant'));

        if ($tenantId === '' || ! ctype_digit($tenantId)) {
            $this->error('--tenant 必须是正整数租户 ID');

            return self::FAILURE;
        }

        try {
            $results = TenantContext::runInTenant(
                (int) $tenantId,
                fn (): array => $service->verify((int) $tenantId),
            );
        } catch (\Throwable $e) {
            $this->error('校验失败：' . $e->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('尚未配置模板映射（fail closed：建项目会被拒绝）');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($results as $result) {
            if (! $result['ok']) {
                $failed++;
            }
        }

        $this->table(
            ['template_map_id', 'project_type', 'template_id', '结果', '错误'],
            array_map(static fn (array $result): array => [
                $result['template_map_id'],
                $result['project_type'],
                $result['template_id'],
                $result['ok'] ? 'ok' : 'invalid',
                $result['error'] ?? '-',
            ], $results),
        );

        if ($failed > 0) {
            $this->error(sprintf('%d 条映射校验失败（不会静默套用默认模板）', $failed));

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'dry-run：全部映射有效（未写库）' : '全部映射有效');

        return self::SUCCESS;
    }
}
