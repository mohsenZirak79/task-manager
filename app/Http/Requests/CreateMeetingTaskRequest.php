<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasTaskPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateMeetingTaskRequest extends FormRequest
{
    use HasTaskPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->taskPayloadRules();
        $rules['title'] = ['sometimes', 'string', 'max:255'];
        $rules['short_description'] = ['sometimes', 'nullable', 'string', 'max:100'];

        return $rules;
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->addSubmitValidationErrors($validator)];
    }
}
