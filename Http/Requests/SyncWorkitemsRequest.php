<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncWorkitemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'yunxiao_project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'connection_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
