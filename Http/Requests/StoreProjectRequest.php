<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'project_type' => ['required', 'string', 'max:100'],
            'custom_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{4,6}$/'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'project_manager' => ['sometimes', 'nullable', 'string', 'max:100'],
            'members' => ['sometimes', 'array', 'max:100'],
            'members.*' => ['string', 'max:100'],
            'connection_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'custom_code.regex' => 'customCode 必须是 4–6 位大写字母（平台不会静默换码）',
        ];
    }
}
