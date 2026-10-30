<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 连接脱敏出口：绝不包含 credentials/token 片段
 */
class ConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
