<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasMeetingPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitMeetingRequest extends FormRequest
{
    use HasMeetingPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->merge(['submit' => true]);
            $this->addMeetingSubmitErrors($validator, $this->route('meeting'));
        }];
    }
}
