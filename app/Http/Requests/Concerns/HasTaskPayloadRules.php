<?php

namespace App\Http\Requests\Concerns;

use App\Enums\TaskSubmissionType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait HasTaskPayloadRules
{
    /** @return array<string, array<int, mixed>> */
    protected function taskPayloadRules(bool $updating = false): array
    {
        $userExists = Rule::exists('users', 'id')
            ->whereNull('deleted_at')
            ->where('is_active', true);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['sometimes', 'string', 'max:100'],
            'submission_type' => [$updating ? 'sometimes' : 'required', Rule::enum(TaskSubmissionType::class)],
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
            'attachment_file_ids' => ['sometimes', 'array', 'max:20'],
            'attachment_file_ids.*' => ['integer', 'distinct', Rule::exists('media_files', 'id')->where('category', 'attachment')],
            'planning_items' => ['sometimes', 'array', 'max:100'],
            'planning_items.*.title' => ['required', 'string', 'max:255'],
            'planning_items.*.weight' => ['required', 'numeric', 'min:0'],
            'planning_items.*.progress_percentage' => ['sometimes', 'integer', 'between:0,100'],
            'planning_items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
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

        $value = fn (string $field) => array_key_exists($field, $this->all())
            ? $this->input($field)
            : $existing?->{$field};

        if (blank($value('title'))) {
            $validator->errors()->add('title', 'عنوان هنگام ارسال اجباری است.');
        }

        if (blank($value('short_description'))) {
            $validator->errors()->add('short_description', 'توضیحات مختصر هنگام ارسال اجباری است.');
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

        $submissionType = $this->input('submission_type', $existing?->submission_type?->value);
        if ($submissionType !== TaskSubmissionType::Request->value) {
            return;
        }

        $this->requireCollectionForSubmit($validator, 'tags', $existing, 'tags', 'برای ارسال درخواست حداقل یک تگ لازم است.');
        $this->requireCollectionForSubmit($validator, 'follower_ids', $existing, 'followers', 'برای ارسال درخواست حداقل یک پیرو لازم است.');
        $this->requireCollectionForSubmit($validator, 'supervisor_ids', $existing, 'supervisors', 'برای ارسال درخواست حداقل یک ناظر لازم است.');
    }

    private function requireCollectionForSubmit(
        Validator $validator,
        string $input,
        mixed $existing,
        string $relation,
        string $message,
    ): void {
        $hasInput = array_key_exists($input, $this->all());
        $hasExisting = $existing?->{$relation}()->exists() ?? false;
        if (($hasInput && count((array) $this->input($input, [])) === 0) || (! $hasInput && ! $hasExisting)) {
            $validator->errors()->add($input, $message);
        }
    }
}
