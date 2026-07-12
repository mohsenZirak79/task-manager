<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role_id' => ['required', 'exists:roles,id'], 'started_at' => ['nullable', 'date'], 'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'], 'is_active' => ['boolean']];
    }
}
