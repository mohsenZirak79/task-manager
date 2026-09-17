<?php

namespace App\Http\Requests;

use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'mobile' => ['sometimes', 'required', 'string', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'birth_date' => ['nullable', 'date'],
            'internal_phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'access_role_ids' => ['sometimes', 'array'],
            'access_role_ids.*' => ['integer', 'distinct', 'exists:access_roles,id'],
            'org_position_ids' => ['sometimes', 'array'],
            'org_position_ids.*' => ['integer', 'distinct', 'exists:org_positions,id'],
            'special_dates' => ['nullable', 'array'],
            'special_dates.*.title' => ['required_with:special_dates', 'string', 'max:255'],
            'special_dates.*.date' => ['required_with:special_dates', 'date'],
            'special_dates.*.description' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mobile')) {
            $this->merge(['mobile' => MobileNumber::normalize($this->input('mobile'))]);
        }
    }
}
