<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_type' => ['required', 'string', 'max:100'],
            'template_id' => ['required', 'string', 'max:100'],
            'workitem_type_map' => ['sometimes', 'array'],
            'workitem_type_map.*' => ['string', 'max:100'],
            'status_map' => ['sometimes', 'array'],
            'status_map.*' => ['string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
            'connection_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
