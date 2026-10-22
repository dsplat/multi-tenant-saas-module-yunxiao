<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Integration\Models\IntegrationOutboxMessage;

/**
 * 写回记录出口（脱敏，不含任何凭据）
 *
 * @mixin IntegrationOutboxMessage
 */
class WritebackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = (array) ($this->payload ?? []);

        return [
            'message_id' => (int) $this->message_id,
            'connection_id' => (int) $this->connection_id,
            'action' => $this->action,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'available_at' => $this->available_at?->format('Y-m-d H:i:s'),
            'last_error' => $this->last_error,
            'external_ref' => $this->external_ref,
            'sent_at' => $this->sent_at?->format('Y-m-d H:i:s'),
            'workitem_id' => $payload['workitem_id'] ?? null,
            'external_workitem_id' => $payload['external_workitem_id'] ?? null,
            'serial_number' => $payload['serial_number'] ?? null,
            'status_id' => $payload['status_id'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'gitlab' => $payload['gitlab'] ?? null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
