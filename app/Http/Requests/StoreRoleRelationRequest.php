<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['parent_role_id' => ['required', 'exists:roles,id'], 'child_role_id' => ['required', 'exists:roles,id', 'different:parent_role_id'], 'relation_type' => ['required', Rule::in(['top_down', 'same_level'])]];
    }
}
