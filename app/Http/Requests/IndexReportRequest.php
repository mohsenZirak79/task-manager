<?php

namespace App\Http\Requests;

use App\Enums\ReportStatus;
use App\Http\Requests\Concerns\ValidatesListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexReportRequest extends FormRequest
{
    use ValidatesListFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->textFilterRules(['search', 'report_number', 'title', 'recipient_search', 'creator_search']),
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'creator_user_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'meeting_id' => ['nullable', 'integer', 'min:1', Rule::exists('meetings', 'id')->whereNull('deleted_at')],
            'resolution_id' => ['nullable', 'integer', 'min:1', 'exists:meeting_resolutions,id'],
            'recipient_user_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'tag_id' => ['nullable', 'integer', 'min:1', 'exists:tags,id'],
            ...$this->dateRangeRules('created_from', 'created_to'),
            ...$this->paginationRules(),
        ];
    }
}
