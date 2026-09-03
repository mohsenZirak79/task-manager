<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_admin', 0),
                Rule::unique('roles', 'user_id')->ignore($roleId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'visible_tabs' => ['sometimes', 'array'],
            'visible_tabs.*' => ['string', 'in:tasks,requests,users,organization,settings,contact'],
            'request_up_levels' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'assignment_down_levels' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('parent_id') && (int) $this->input('parent_id') === $this->route('role')?->id) {
                    $validator->errors()->add('parent_id', 'یک نقش نمی‌تواند والد خودش باشد.');
                }
            },
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
