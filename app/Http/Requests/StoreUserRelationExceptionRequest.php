<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRelationExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['from_user_id' => ['required', 'exists:users,id'], 'to_user_id' => ['required', 'exists:users,id', 'different:from_user_id'], 'permission_type' => ['required', Rule::in(['allow', 'deny'])], 'description' => ['nullable', 'string']];
    }
}
