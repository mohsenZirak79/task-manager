<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasMeetingPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMeetingRequest extends FormRequest
{
    use HasMeetingPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->meetingPayloadRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->addMeetingSubmitErrors($validator)];
    }
}
