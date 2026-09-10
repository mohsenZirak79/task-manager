<?php

namespace App\Http\Requests;

use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'birth_date' => ['nullable', 'date'],
            'internal_phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'is_admin' => ['sometimes', 'boolean'],
            'is_super_admin' => ['prohibited'],
            'password' => ['nullable', 'confirmed', 'min:8'],
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
