<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 云效工作项导入账本（需求导入云效，issue #1）
 *
 * 业务幂等键 `(tenant_id, yunxiao_project_id, idempotency_key)`：
 * - created：远端已创建，重放直接返回 external_workitem_id；
 * - failed：失败可同键重试；
 * - pending：已在调用远端前落账但未回填（进程崩溃），显式返回 uncertain，不自动重试。
 */
class YunxiaoWorkitemImport extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    protected $table = 'yunxiao_workitem_imports';

    protected $primaryKey = 'import_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'yunxiao_project_id', 'idempotency_key',
        'payload_hash', 'status', 'external_workitem_id', 'serial_number',
        'subject', 'workitem_type_id', 'assigned_to', 'attempts', 'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
