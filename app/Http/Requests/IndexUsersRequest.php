<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesListFilters;
use Illuminate\Foundation\Http\FormRequest;

class IndexUsersRequest extends FormRequest
{
    use ValidatesListFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->textFilterRules(['search', 'username', 'full_name', 'email', 'mobile', 'org_position_title']),
            'org_position_id' => ['nullable', 'integer', 'min:1', 'exists:org_positions,id'],
            'is_active' => ['nullable', 'boolean'],
            ...$this->dateRangeRules('last_login_from', 'last_login_to'),
            ...$this->dateRangeRules('last_activity_from', 'last_activity_to'),
            ...$this->numberRangeRules('tasks_count_min', 'tasks_count_max'),
            ...$this->paginationRules(),
        ];
    }
}
