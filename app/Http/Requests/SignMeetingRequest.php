<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sign', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [
            'content_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'user_id' => ['prohibited'],
            'attendee_user_id' => ['prohibited'],
            'signed_at' => ['prohibited'],
            'signature_file_id' => ['prohibited'],
            'content_snapshot' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'content_version.required' => 'نسخه صورت‌جلسه مشاهده‌شده الزامی است.',
            'content_version.regex' => 'نسخه صورت‌جلسه معتبر نیست.',
            'user_id.prohibited' => 'هر کاربر فقط می‌تواند از طرف خودش امضا کند.',
            'attendee_user_id.prohibited' => 'هر کاربر فقط می‌تواند از طرف خودش امضا کند.',
            'signed_at.prohibited' => 'زمان امضا فقط توسط سرور ثبت می‌شود.',
        ];
    }
}
