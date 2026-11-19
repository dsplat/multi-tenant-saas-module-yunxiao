<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Yunxiao\Http\Requests\ImportWorkitemsRequest;
use MultiTenantSaas\Modules\Yunxiao\Services\YunxiaoWorkitemImportService;

/**
 * 需求导入云效（工作项创建，issue #1）
 *
 * 受 capability `common.yunxiao.workitem.import` 保护；逐条返回成功/重放/失败明细，
 * 单条失败不中断整批。幂等键与重试语义见 YunxiaoWorkitemImportService。
 */
class WorkitemImportController extends BaseController
{
    public function __construct(private readonly YunxiaoWorkitemImportService $imports) {}

    public function store(ImportWorkitemsRequest $request, string $id): JsonResponse
    {
        $result = $this->imports->import((int) TenantContext::getId(), (int) $id, $request->validated());

        $message = match (true) {
            $result['dry_run'] => '需求导入预检完成（未写入云效）',
            $result['failed'] > 0 || $result['uncertain'] > 0 => '需求导入完成（部分失败）',
            default => '需求已导入云效',
        };

        return $this->successResponse($result, $message);
    }
}
