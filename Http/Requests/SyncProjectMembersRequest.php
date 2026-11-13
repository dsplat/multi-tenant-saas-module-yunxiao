<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 云效项目成员对账（issue #44）
 *
 * 省略 members / project_manager 时沿用项目 metadata 里的期望集合；
 * 只传其中一个键时，另一个键继承现值（不会因部分更新清空成员）。
 */
class SyncProjectMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'members' => ['sometimes', 'array', 'max:100'],
            'members.*' => ['string', 'max:100'],
            'project_manager' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            // 只移除「平台添加过且已不在期望集合」的成员；手工在云效加的成员不动。
            'prune' => ['sometimes', 'boolean'],
        ];
    }
}
