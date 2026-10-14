<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCommentMentionUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
