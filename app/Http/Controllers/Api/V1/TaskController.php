<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeTaskStatusRequest;
use App\Http\Requests\EligibleTaskUsersRequest;
use App\Http\Requests\IndexTaskRequest;
use App\Http\Requests\RejectTaskCompletionRequest;
use App\Http\Requests\RejectTaskRequest;
use App\Http\Requests\RequestTaskRevisionRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskPlanningProgressRequest;
use App\Http\Requests\UpdateTaskProgressRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Task;
use App\Models\TaskPlanningItem;
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
        $request->user()->loadMissing('accessRoles.permissions');
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
        $taskId = $request->validated('task_id');
        if ($taskId) {
            Gate::authorize('update', Task::query()->findOrFail($taskId));
        } else {
            Gate::authorize('create', Task::class);
        }

        $submissionType = $request->validated('submission_type');
        $users = $this->taskService->eligibleUsers(
            $request->validated('search'),
            $request->user(),
            $submissionType ? TaskSubmissionType::from($submissionType) : null,
        );

        return $this->success('کاربران مجاز تسک', [
            'assignment_targets' => UserSummaryResource::collection($users['assignment_targets']),
            'request_targets' => UserSummaryResource::collection($users['request_targets']),
            'participants' => UserSummaryResource::collection($users['participants']),
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

    public function destroy(Task $task): JsonResponse
    {
        Gate::authorize('delete', $task);
        $this->taskService->delete($task);

        return $this->success('پیش‌نویس تسک با موفقیت حذف شد.');
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

    public function updatePlanningProgress(
        UpdateTaskPlanningProgressRequest $request,
        Task $task,
        TaskPlanningItem $planningItem,
    ): JsonResponse {
        Gate::authorize('updatePlanningProgress', $task);
        $task = $this->taskService->updatePlanningProgress(
            $task,
            $planningItem,
            $request->user(),
            (int) $request->validated('progress_percentage'),
        );

        return $this->success('پیشرفت آیتم برنامه‌ریزی ثبت شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function requestCompletion(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('requestCompletion', $task);
        $task = $this->taskService->requestCompletion($task, $request->user());

        return $this->success('درخواست تأیید پایان تسک ثبت شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function approveCompletion(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('approveCompletion', $task);
        $task = $this->taskService->approveCompletion($task, $request->user());

        return $this->success('پایان تسک تأیید شد.', [
            'task' => new TaskResource($task),
        ]);
    }

    public function rejectCompletion(RejectTaskCompletionRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('rejectCompletion', $task);
        $task = $this->taskService->rejectCompletion(
            $task,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success('پایان تسک رد و برای ادامه اجرا بازگردانده شد.', [
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
