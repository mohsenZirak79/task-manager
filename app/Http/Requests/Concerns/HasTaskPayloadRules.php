<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait HasTaskPayloadRules
{
    /** @return array<string, array<int, mixed>> */
    protected function taskPayloadRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $userExists = Rule::exists('users', 'id')
            ->whereNull('deleted_at')
            ->where('is_active', true);

        return [
            'title' => [$required, 'string', 'max:255'],
            'short_description' => [$required, 'string', 'max:100'],
            'request_description' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'progress_percentage' => ['sometimes', 'integer', 'between:0,100'],
            'requester_id' => ['sometimes', 'nullable', 'integer', $userExists],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['integer', 'distinct', $userExists],
            'follower_ids' => ['sometimes', 'array'],
            'follower_ids.*' => ['integer', 'distinct', $userExists],
            'supervisor_ids' => ['sometimes', 'array'],
            'supervisor_ids.*' => ['integer', 'distinct', $userExists],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:100', 'distinct'],
            'financial_resources' => ['sometimes', 'nullable', 'string'],
            'financial_estimated_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'financial_provider_user_id' => ['sometimes', 'nullable', 'integer', $userExists],
            'equipment_resources' => ['sometimes', 'nullable', 'string'],
            'equipment_estimated_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'equipment_provider_user_id' => ['sometimes', 'nullable', 'integer', $userExists],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    protected function addSubmitValidationErrors(Validator $validator, mixed $existing = null): void
    {
        if (! $this->boolean('submit')) {
            return;
        }

        $hasDurationInput = array_key_exists('duration_minutes', $this->all());
        $durationIsMissing = $this->input('duration_minutes') === null || $this->input('duration_minutes') === '';
        if ($durationIsMissing && ($hasDurationInput || $existing === null || $existing->duration_minutes === null)) {
            $validator->errors()->add('duration_minutes', 'مدت‌زمان هنگام ارسال اجباری است.');
        }

        $hasDueDateInput = array_key_exists('due_date', $this->all());
        $dueDateIsMissing = $this->input('due_date') === null || $this->input('due_date') === '';
        if ($dueDateIsMissing && ($hasDueDateInput || $existing === null || $existing->due_date === null)) {
            $validator->errors()->add('due_date', 'تاریخ اتمام هنگام ارسال اجباری است.');
        }

        $hasAssigneeInput = array_key_exists('assignee_ids', $this->all());
        $hasExistingAssignee = $existing?->participantRecords()
            ->where('role', 'assignee')->exists() ?? false;
        if (($hasAssigneeInput && count((array) $this->input('assignee_ids', [])) === 0)
            || (! $hasAssigneeInput && ! $hasExistingAssignee)) {
            $validator->errors()->add('assignee_ids', 'برای ارسال تسک حداقل یک مسئول انجام لازم است.');
        }
    }
}
