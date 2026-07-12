<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->route('role')?->id)], 'level' => ['sometimes', 'required', 'integer', 'min:1'], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']];
    }
}
