<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 云效项目投影（宿主项目 ↔ 云效项目）
 *
 * 外部权威在云效；本表只保存建项结果、映射状态与同步时间。
 */
class YunxiaoProject extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_CREATION_FAILED = 'creation_failed';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PARTIAL,
        self::STATUS_CREATION_FAILED,
    ];

    protected $table = 'yunxiao_projects';

    protected $primaryKey = 'yunxiao_project_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'project_id', 'external_project_id', 'name',
        'custom_code', 'template_id', 'scope', 'status', 'last_synced_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<YunxiaoWorkitem, $this>
     */
    public function workitems(): HasMany
    {
        return $this->hasMany(YunxiaoWorkitem::class, 'yunxiao_project_id', 'yunxiao_project_id');
    }

    public function isMappingPending(): bool
    {
        return (bool) ($this->metadata['mapping_pending'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function assignmentFailures(): array
    {
        return array_values(array_map('strval', (array) ($this->metadata['assignment_failures'] ?? [])));
    }
}
