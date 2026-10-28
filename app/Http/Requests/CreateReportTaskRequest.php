<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;

class CreateReportTaskRequest extends StoreTaskRequest
{
    public function authorize(): bool
    {
        return Gate::allows('createTask', $this->route('report'));
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'submission_key' => ['required', 'uuid'],
            'source_report_id' => ['prohibited'],
            'report_submission_key' => ['prohibited'],
            'report_request_hash' => ['prohibited'],
        ];
    }
}
