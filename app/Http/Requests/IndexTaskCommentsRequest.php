<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTaskCommentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:task_comments,id'],
            'sort' => ['sometimes', Rule::in(['oldest', 'newest'])],
        ];
    }
}
