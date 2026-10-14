<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskCommentRequest extends FormRequest
{
    use Concerns\HasCommentMentionRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000'], ...$this->commentMentionRules()];
    }
}
