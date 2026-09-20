<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTaskCommentsRequest;
use App\Http\Requests\ReactToTaskCommentRequest;
use App\Http\Requests\StoreTaskCommentRequest;
use App\Http\Requests\UpdateTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\TaskCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskCommentController extends Controller
{
    public function __construct(private readonly TaskCommentService $comments) {}

    public function index(IndexTaskCommentsRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task);
        $request->user()->loadMissing('accessRoles.permissions');
        $comments = $this->comments->paginate($task, $request->validated(), $request->user());

        return $this->success('لیست دیدگاه‌های تسک', [
            'items' => TaskCommentResource::collection($comments->items()),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    public function store(StoreTaskCommentRequest $request, Task $task): JsonResponse
    {
        $parentId = $request->validated('parent_id');
        if ($parentId !== null) {
            Gate::authorize('reply', $this->comments->findForTask($task, (int) $parentId, true));
        } else {
            Gate::authorize('comment', $task);
        }
        $comment = $this->comments->create($task, $request->validated(), $request->user());

        return $this->success('دیدگاه با موفقیت ثبت شد.', [
            'comment' => new TaskCommentResource($comment),
        ], 201);
    }

    public function update(UpdateTaskCommentRequest $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->ensureNested($task, $comment);
        Gate::authorize('update', $comment);
        $comment = $this->comments->update($comment, $request->validated('body'), $request->user());

        return $this->success('دیدگاه با موفقیت ویرایش شد.', [
            'comment' => new TaskCommentResource($comment),
        ]);
    }

    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->ensureNested($task, $comment);
        Gate::authorize('delete', $comment);
        $this->comments->delete($comment);

        return $this->success('دیدگاه با موفقیت حذف شد.');
    }

    public function reaction(ReactToTaskCommentRequest $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->ensureNested($task, $comment);
        Gate::authorize('react', $comment);
        $reactions = $this->comments->react($comment, $request->validated('reaction'), $request->user());

        return $this->success('واکنش با موفقیت ثبت شد.', ['reactions' => $reactions]);
    }

    private function ensureNested(Task $task, TaskComment $comment): void
    {
        abort_unless($comment->task_id === $task->id, 404);
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
