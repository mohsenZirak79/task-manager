<?php

namespace App\Http\Requests;

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
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'birth_date' => ['nullable', 'date'],
            'internal_phone' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'password' => ['nullable', 'confirmed', 'min:8'],
            'special_dates' => ['nullable', 'array'],
            'special_dates.*.title' => ['required_with:special_dates', 'string', 'max:255'],
            'special_dates.*.date' => ['required_with:special_dates', 'date'],
            'special_dates.*.description' => ['nullable', 'string'],
        ];
    }
}
