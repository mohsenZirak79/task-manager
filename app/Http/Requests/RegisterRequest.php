<?php

namespace App\Http\Requests;

use App\Support\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mobile')) {
            $this->merge(['mobile' => MobileNumber::normalize($this->input('mobile'))]);
        }
    }
}
