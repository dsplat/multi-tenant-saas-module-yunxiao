<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 添加云效项目成员（issue #44）
 *
 * user_ids 是云效 userId（不是平台用户主键）；平台账号到云效账号的映射由宿主负责。
 */
class AddProjectMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['string', 'max:100'],
            // 云效 CreateProjectMember 的 roleId 必填；缺省由 config('yunxiao.members.role_id') 提供。
            'role_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
