<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOrgPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:org_positions,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'request_up_levels' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'assignment_down_levels' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->has('parent_id') && (int) $this->input('parent_id') === $this->route('org_position')?->id) {
                $validator->errors()->add('parent_id', 'یک جایگاه نمی‌تواند والد خودش باشد.');
            }
        }];
    }
}
