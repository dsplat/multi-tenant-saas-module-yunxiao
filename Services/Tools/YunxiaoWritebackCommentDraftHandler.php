<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWritebackService;

/**
 * 写回评论草稿（L1）：只生成文本，不入队、不提交
 */
class YunxiaoWritebackCommentDraftHandler implements ToolHandlerContract
{
    public function __construct(private readonly YunxiaoWritebackService $writeback) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $draft = $this->writeback->draftComment([
            'kind' => $arguments['kind'] ?? null,
            'action' => $arguments['action'] ?? null,
            'summary' => $arguments['summary'] ?? null,
            'url' => $arguments['url'] ?? null,
            'event_type' => $arguments['event_type'] ?? null,
        ]);

        return [
            'source' => 'yunxiao_external_untrusted',
            'draft' => $draft,
            'submitted' => false,
            'note' => '仅为草稿：提交须由用户确认后经 Integration outbox 写回',
        ];
    }
}
