<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'auth_type' => ['sometimes', 'string', 'in:bearer,token,none'],
            'config' => ['sometimes', 'array'],
            // Region：组织专属域名，路径不拼 org；Central：中心组织，路径必带 organizationId；
            // Account：账号级令牌，走 /oapi/v1/platform 组织发现/成员/部门/角色。
            'config.scope' => ['sometimes', 'string', 'in:Region,Central,Account'],
            'config.auth_style' => ['sometimes', 'string', 'in:token,bearer'],
            // 不用 sometimes：字段缺省时 required_if 必须仍能触发（sometimes 会整条跳过）。
            'config.org_id' => ['nullable', 'required_if:config.scope,Central', 'string', 'max:100'],
            'config.org_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'config.default_org_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.org_allowlist' => ['sometimes', 'nullable', 'array', 'max:100'],
            'config.org_allowlist.*' => ['string', 'max:100'],
            'config.region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.default_template_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.token_expires_at' => ['sometimes', 'nullable', 'date'],
            'config.timeout' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'config.rate_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'config.writeback_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
