<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Yunxiao\Models\YunxiaoTemplateMap;

/**
 * @mixin YunxiaoTemplateMap
 */
class TemplateMapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'template_map_id' => (int) $this->template_map_id,
            'project_type' => $this->project_type,
            'template_id' => $this->template_id,
            'workitem_type_map' => (array) ($this->workitem_type_map ?? []),
            'status_map' => (array) ($this->status_map ?? []),
            'is_default' => (bool) $this->is_default,
            'metadata' => (array) ($this->metadata ?? []),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
