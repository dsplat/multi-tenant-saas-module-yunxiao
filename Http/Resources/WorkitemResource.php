<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoWorkitem;

/**
 * 工作项投影出口：不含完整描述正文
 *
 * @mixin YunxiaoWorkitem
 */
class WorkitemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'yunxiao_workitem_id' => (int) $this->yunxiao_workitem_id,
            'connection_id' => (int) $this->connection_id,
            'yunxiao_project_id' => (int) $this->yunxiao_project_id,
            'external_workitem_id' => $this->external_workitem_id,
            'serial_number' => $this->serial_number,
            'workitem_type_id' => $this->workitem_type_id,
            'subject' => $this->subject,
            'status_id' => $this->status_id,
            'status_name' => $this->status_name,
            'assigned_to' => $this->assigned_to,
            'gmt_created' => $this->gmt_created?->format('Y-m-d H:i:s'),
            'gmt_modified' => $this->gmt_modified?->format('Y-m-d H:i:s'),
            'fetched_at' => $this->fetched_at?->format('Y-m-d H:i:s'),
            'unmapped' => $this->isUnmapped(),
            'metadata' => (array) ($this->metadata ?? []),
        ];
    }
}
