<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('report'));
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('task_comments', 'id')
                ->where('report_id', $this->route('report')->id)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['parent_id.exists' => 'دیدگاه والد باید متعلق به همین گزارش و حذف‌نشده باشد.'];
    }
}
