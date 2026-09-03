<?php

namespace App\Http\Requests;

use App\Models\AuthOtp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'purpose' => ['required', Rule::in([AuthOtp::PURPOSE_SET_PASSWORD])],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }
}
