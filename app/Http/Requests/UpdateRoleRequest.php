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
                'required',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('roles', 'user_id')->ignore($roleId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
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
        ];
    }
}
