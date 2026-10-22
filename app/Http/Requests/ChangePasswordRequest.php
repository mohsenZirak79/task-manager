<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => [
                'bail', 'required', 'string', 'confirmed', 'different:current_password',
                Password::min(8)->letters()->numbers()->symbols(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Bcrypt rejects passwords over 72 bytes, including multibyte input.
                    if (strlen($value) > 72) {
                        $fail('رمز عبور جدید نباید بیشتر از ۷۲ بایت باشد.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'رمز عبور فعلی را وارد کنید.',
            'password.required' => 'رمز عبور جدید را وارد کنید.',
            'password.confirmed' => 'رمز عبور جدید و تکرار آن مطابقت ندارند.',
            'password.different' => 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.',
            'password.min' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.',
            'password.letters' => 'رمز عبور جدید باید حداقل یک حرف داشته باشد.',
            'password.numbers' => 'رمز عبور جدید باید حداقل یک عدد داشته باشد.',
            'password.symbols' => 'رمز عبور جدید باید حداقل یک نماد داشته باشد.',
        ];
    }
}
