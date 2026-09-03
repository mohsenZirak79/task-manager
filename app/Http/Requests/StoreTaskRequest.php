<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasTaskPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    use HasTaskPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->taskPayloadRules();
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->addSubmitValidationErrors($validator);
            },
        ];
    }
}
