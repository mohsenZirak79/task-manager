<?php

namespace App\Http\Requests;

use App\Models\AuthOtp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', Rule::in(AuthOtp::PURPOSES)],
        ];
    }
}
