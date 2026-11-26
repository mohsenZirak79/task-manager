<?php

namespace App\Http\Requests;

use App\Enums\MeetingStatus;
use App\Http\Requests\Concerns\ValidatesListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMeetingRequest extends FormRequest
{
    use ValidatesListFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->textFilterRules(['search', 'meeting_number', 'title', 'location']),
            'actor_role' => ['nullable', Rule::in(['creator', 'chairman', 'secretary', 'attendee'])],
            'status' => ['nullable', Rule::enum(MeetingStatus::class)],
            ...$this->dateRangeRules('from_date', 'to_date'),
            ...$this->paginationRules(),
        ];
    }
}
