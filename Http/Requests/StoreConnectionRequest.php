<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'token' => ['required', 'string', 'max:4096'],
            'auth_type' => ['sometimes', 'string', 'in:bearer,token,none'],
            'config' => ['sometimes', 'array'],
            'config.scope' => ['sometimes', 'string', 'in:Region'],
            'config.auth_style' => ['sometimes', 'string', 'in:token,bearer'],
            'config.org_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.org_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'config.region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.default_template_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.token_expires_at' => ['sometimes', 'nullable', 'date'],
            'config.timeout' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'config.rate_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'config.writeback_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
