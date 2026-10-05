<?php

namespace App\Http\Requests;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ReportStatus::class)],
            'creator_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'search' => ['sometimes', 'string', 'max:255'],
            'meeting_id' => ['sometimes', 'integer', Rule::exists('meetings', 'id')->whereNull('deleted_at')],
            'resolution_id' => ['sometimes', 'integer', 'exists:meeting_resolutions,id'],
            'recipient_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'tag_id' => ['sometimes', 'integer', 'exists:tags,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
