<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReportTaskRequest;
use App\Http\Requests\IndexReportTasksRequest;
use App\Http\Resources\TaskResource;
use App\Models\Report;
use App\Models\Task;
use App\Services\ReportTaskService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReportTaskController extends Controller
{
    public function store(CreateReportTaskRequest $request, Report $report, ReportTaskService $service): JsonResponse
    {
        $result = $service->create($report, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'تسک مرتبط با گزارش ثبت شد.',
            'data' => ['task' => new TaskResource($result['task']), 'replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }

    public function index(IndexReportTasksRequest $request, Report $report, TaskService $service): JsonResponse
    {
        Gate::authorize('view', $report);
        Gate::authorize('viewAny', Task::class);
        $tasks = $service->paginate($request->validated(), $request->user(), $report);

        return response()->json([
            'success' => true,
            'message' => 'تسک‌های مرتبط با گزارش',
            'data' => [
                'items' => TaskResource::collection($tasks->items()),
                'meta' => [
                    'current_page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(), 'total' => $tasks->total(),
                ],
            ],
        ]);
    }
}
