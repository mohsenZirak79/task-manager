<?php

namespace App\Services;

use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReportTaskService
{
    public function __construct(private readonly TaskService $tasks) {}

    /** @return array{task: Task, replayed: bool} */
    public function create(Report $report, array $data, User $actor): array
    {
        return DB::transaction(function () use ($report, $data, $actor): array {
            // Serialize retries and report mutations using the same parent row lock.
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $actor->unsetRelation('orgPositions');
            Gate::forUser($actor)->authorize('createTask', $report);
            $key = strtolower($data['submission_key']);
            $payload = Arr::except($data, ['submission_key']);
            $hash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $existing = $report->tasks()->where('created_by', $actor->id)
                ->where('report_submission_key', $key)->first();

            if ($existing) {
                if (! hash_equals($existing->report_request_hash, $hash)) {
                    throw new ConflictHttpException('این کلید ثبت قبلاً برای اطلاعات دیگری استفاده شده است.');
                }

                return ['task' => $this->tasks->find($existing), 'replayed' => true];
            }

            $task = $this->tasks->create([
                'title' => $report->title,
                'short_description' => mb_substr($report->short_description, 0, 100),
                'request_description' => $report->description,
                ...$payload,
            ], $actor);
            $task->forceFill([
                'source_report_id' => $report->id,
                'report_submission_key' => $key,
                'report_request_hash' => $hash,
            ])->save();

            return ['task' => $this->tasks->find($task), 'replayed' => false];
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
