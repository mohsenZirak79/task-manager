<?php

namespace App\Http\Requests;

use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'birth_date' => ['nullable', 'date'],
            'internal_phone' => ['nullable', 'string', 'max:50'],
            'avatar_file_id' => [
                'nullable',
                'integer',
                Rule::exists('media_files', 'id')->where(fn ($query) => $query->where('mime_type', 'like', 'image/%')),
            ],
            'is_active' => ['boolean'],
            'access_role_ids' => ['sometimes', 'array'],
            'access_role_ids.*' => ['integer', 'distinct', 'exists:access_roles,id'],
            'org_position_ids' => ['sometimes', 'array'],
            'org_position_ids.*' => ['integer', 'distinct', 'exists:org_positions,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
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
