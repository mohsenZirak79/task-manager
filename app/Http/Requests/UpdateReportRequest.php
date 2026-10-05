<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activeUser = Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true);
        $recipientId = (int) $this->input('recipient_user_id', $this->route('report')?->recipient_user_id);

        return [
            'status' => ['prohibited'],
            'report_number' => ['prohibited'],
            'sent_at' => ['prohibited'],
            'viewed_at' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'short_description' => ['sometimes', 'required', 'string', 'max:500'],
            'description' => ['sometimes', 'required', 'string'],
            'recipient_user_id' => ['sometimes', 'required', 'integer', $activeUser],
            'cc_user_ids' => ['sometimes', 'array', 'max:100'],
            'cc_user_ids.*' => ['integer', 'distinct', $activeUser, Rule::notIn([$recipientId])],
            'attachment_file_ids' => ['sometimes', 'array', 'max:20'],
            'attachment_file_ids.*' => ['required', 'integer', 'distinct', Rule::exists('media_files', 'id')->where('category', 'attachment')],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_user_id.exists' => 'گیرنده اصلی باید یک کاربر فعال باشد.',
            'cc_user_ids.*.exists' => 'همه کاربران رونوشت باید فعال باشند.',
            'cc_user_ids.*.not_in' => 'گیرنده اصلی نباید هم‌زمان در رونوشت باشد.',
        ];
    }
}
