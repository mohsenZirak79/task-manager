<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasTaskPayloadRules;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateMeetingTaskRequest extends FormRequest
{
    use HasTaskPayloadRules;

    public function authorize(): bool
    {
        return $this->user()->can('manageResolutions', $this->route('meeting')) && $this->user()->can('create', Task::class);
    }

    protected function prepareForValidation(): void
    {
        $ids = (array) $this->input('assignee_ids', []);
        $personal = count($ids) === 1 && (int) reset($ids) === $this->user()->id;
        $this->merge(['submit' => true, 'submission_type' => $personal ? 'assignment' : 'request']);
    }

    public function rules(): array
    {
        $rules = $this->taskPayloadRules();
        $rules['title'] = ['required', 'string', 'max:255'];
        $rules['short_description'] = ['required', 'string', 'max:100'];
        $rules['assignee_ids'] = ['required', 'array', 'min:1'];

        return $rules;
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->addSubmitValidationErrors($validator)];
    }
}
