<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoProject;

/**
 * @mixin YunxiaoProject
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'yunxiao_project_id' => (int) $this->yunxiao_project_id,
            'connection_id' => (int) $this->connection_id,
            'project_id' => (int) $this->project_id,
            'external_project_id' => $this->external_project_id,
            'name' => $this->name,
            'custom_code' => $this->custom_code,
            'template_id' => $this->template_id,
            'scope' => $this->scope,
            'status' => $this->status,
            'last_synced_at' => $this->last_synced_at?->format('Y-m-d H:i:s'),
            'mapping_pending' => $this->isMappingPending(),
            'assignment_failures' => $this->assignmentFailures(),
            'metadata' => (array) ($this->metadata ?? []),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
