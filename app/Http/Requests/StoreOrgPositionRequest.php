<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrgPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:org_positions,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'request_up_levels' => ['nullable', 'integer', 'min:0', 'max:255'],
            'assignment_down_levels' => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }
}
