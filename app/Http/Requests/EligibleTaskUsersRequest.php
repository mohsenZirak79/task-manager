<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EligibleTaskUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:255']];
    }
}
