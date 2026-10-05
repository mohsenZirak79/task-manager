<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activeUser = Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true);

        return [
            'status' => ['sometimes', 'required', Rule::in(['draft', 'sent'])],
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'recipient_user_id' => ['required', 'integer', $activeUser],
            'cc_user_ids' => ['sometimes', 'array', 'max:100'],
            'cc_user_ids.*' => ['integer', 'distinct', $activeUser, Rule::notIn([(int) $this->input('recipient_user_id')])],
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
