<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:roles,id'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_admin', 0),
                'unique:roles,user_id',
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'visible_tabs' => ['nullable', 'array'],
            'visible_tabs.*' => ['string', 'in:tasks,requests,users,organization,settings,contact'],
            'request_up_levels' => ['nullable', 'integer', 'min:0'],
            'assignment_down_levels' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'این کاربر قبلاً به نقش دیگری منصوب شده است.',
            'user_id.exists' => 'کاربر مدیر قابل انتساب به نقش سازمانی نیست.',
        ];
    }
}
