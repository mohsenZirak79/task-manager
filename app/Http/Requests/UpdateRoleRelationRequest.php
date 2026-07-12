<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['parent_role_id' => ['sometimes', 'required', 'exists:roles,id'], 'child_role_id' => ['sometimes', 'required', 'exists:roles,id', 'different:parent_role_id'], 'relation_type' => ['sometimes', 'required', Rule::in(['top_down', 'same_level'])]];
    }
}
