<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:task_comments,id'],
        ];
    }
}
