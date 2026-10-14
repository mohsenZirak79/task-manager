<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait HasCommentMentionRules
{
    protected function commentMentionRules(): array
    {
        return [
            'mention_user_ids' => ['sometimes', 'array', 'max:20'],
            'mention_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
