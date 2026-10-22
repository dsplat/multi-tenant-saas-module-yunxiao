<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 云效工作项投影（只读，不存完整描述正文）
 *
 * serialNumber 是对账键；状态原样保存，未知状态标 metadata.unmapped=true。
 */
class YunxiaoWorkitem extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    protected $table = 'yunxiao_workitems';

    protected $primaryKey = 'yunxiao_workitem_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'yunxiao_project_id', 'external_workitem_id',
        'serial_number', 'workitem_type_id', 'subject', 'status_id', 'status_name',
        'assigned_to', 'gmt_created', 'gmt_modified', 'fetched_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'gmt_created' => 'datetime',
            'gmt_modified' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YunxiaoProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(YunxiaoProject::class, 'yunxiao_project_id', 'yunxiao_project_id');
    }

    public function isUnmapped(): bool
    {
        return (bool) ($this->metadata['unmapped'] ?? false);
    }
}
