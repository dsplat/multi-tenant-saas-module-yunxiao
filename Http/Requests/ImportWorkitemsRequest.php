<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 需求导入云效（issue #1）
 *
 * items 是 AI 拆解后的业务需求；assigned_to 是云效 userId（不是平台用户主键）。
 * workitem_type_id 缺省时可用 workitem_type + 模板映射解析。
 */
class ImportWorkitemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxItems = max(1, (int) config('yunxiao.import.max_items', 100));

        return [
            'items' => ['required', 'array', 'min:1', 'max:' . $maxItems],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'items.*.assigned_to' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.assignedTo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.workitem_type_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.workitemTypeId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.workitem_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.priority' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.status_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.statusId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.parent_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.parentId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
            'items.*.custom_field_values' => ['sometimes', 'array'],
            'items.*.customFieldValues' => ['sometimes', 'array'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:100'],
            'workitem_type_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'workitem_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dry_run' => ['sometimes', 'boolean'],
            'retry_pending' => ['sometimes', 'boolean'],
        ];
    }
}
