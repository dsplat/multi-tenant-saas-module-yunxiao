<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 云效模板/状态映射（按宿主项目类型）
 *
 * - workitem_type_map：宿主类型 => 云效工作项类型 ID
 * - status_map：平台事件 => 云效状态 ID（为空时写回只评论不改状态）
 * - is_default 必须由管理员显式设置；解析时无精确映射才回落到默认映射。
 */
class YunxiaoTemplateMap extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    protected $table = 'yunxiao_template_maps';

    protected $primaryKey = 'template_map_id';

    protected $fillable = [
        'tenant_id', 'project_type', 'template_id', 'workitem_type_map',
        'status_map', 'is_default', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'workitem_type_map' => 'array',
            'status_map' => 'array',
            'metadata' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function knownStatusIds(): array
    {
        $known = $this->metadata['known_status_ids'] ?? null;

        if (is_array($known)) {
            return array_values(array_map('strval', $known));
        }

        return array_values(array_map('strval', array_values((array) $this->status_map)));
    }
}
