<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeTaskStatusRequest;
use App\Http\Requests\EligibleTaskUsersRequest;
use App\Http\Requests\IndexTaskRequest;
use App\Http\Requests\RejectTaskRequest;
use App\Http\Requests\RequestTaskRevisionRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskProgressRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\RoleUserResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function index(IndexTaskRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);
        $tasks = $this->taskService->paginate($request->validated(), $request->user());

        return $this->success('لیست تسک‌ها', [
            'items' => TaskResource::collection($tasks->items()),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function eligibleUsers(EligibleTaskUsersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);
        $users = $this->taskService->eligibleUsers($request->validated('search'), $request->user());

        return $this->success('کاربران مجاز تسک', [
            'assignment_targets' => RoleUserResource::collection($users['assignment_targets']),
            'request_targets' => RoleUserResource::collection($users['request_targets']),
            'participants' => RoleUserResource::collection($users['participants']),
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        Gate::authorize('create', Task::class);
        $task = $this->taskService->create($request->validated(), $request->user());

        return $this->success('تسک با موفقیت ایجاد شد.', [
            'task' => new TaskResource($task),
        ], 201);
    }

    public function show(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        return $this->success('جزئیات تسک', [
            'task' => new TaskResource($this->taskService->find($task)),
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('update', $task);
        $task = $this->taskService->update($task, $request->validated(), $request->user());

        return $this->success('تسک با موفقیت بروزرسانی شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function approve(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('approve', $task);
        $task = $this->taskService->approve($task, $request->user());

        return $this->success('درخواست با موفقیت تأیید شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function reject(RejectTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('reject', $task);
        $task = $this->taskService->reject($task, $request->user(), $request->validated('reason'));

        return $this->success('درخواست رد شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function requestRevision(RequestTaskRevisionRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('requestRevision', $task);
        $task = $this->taskService->requestRevision($task, $request->user(), $request->validated('reason'));

        return $this->success('درخواست اصلاح ثبت شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function changeStatus(ChangeTaskStatusRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('changeStatus', $task);
        $task = $this->taskService->changeStatus(
            $task,
            $request->user(),
            TaskStatus::from($request->validated('status')),
        );

        return $this->success('وضعیت تسک تغییر کرد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function updateProgress(UpdateTaskProgressRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('updateProgress', $task);
        $task = $this->taskService->updateProgress(
            $task,
            $request->user(),
            (int) $request->validated('progress_percentage'),
        );

        return $this->success('درصد پیشرفت ثبت شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
