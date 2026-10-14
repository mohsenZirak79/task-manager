<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCommentMentionUsersRequest;
use App\Http\Requests\IndexReportCommentsRequest;
use App\Http\Requests\ReactToTaskCommentRequest;
use App\Http\Requests\StoreReportCommentRequest;
use App\Http\Requests\UpdateTaskCommentRequest;
use App\Http\Resources\ReportCommentResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Report;
use App\Models\TaskComment;
use App\Services\CommentMentionService;
use App\Services\ReportCommentService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReportCommentController extends Controller
{
    public function __construct(private readonly ReportCommentService $comments) {}

    public function mentionUsers(IndexCommentMentionUsersRequest $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);
        app(ReportService::class)->ensureActive($report);

        return $this->success('کاربران قابل منشن', [
            'items' => UserSummaryResource::collection(app(CommentMentionService::class)->eligibleUsers($report, $request->validated('search'))),
        ]);
    }

    public function index(IndexReportCommentsRequest $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);
        $items = $this->comments->paginate($report, $request->validated(), $request->user());

        return $this->success('لیست دیدگاه‌های گزارش', [
            'items' => ReportCommentResource::collection($items->items()),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(), 'total' => $items->total()],
        ]);
    }

    public function store(StoreReportCommentRequest $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return $this->success('دیدگاه با موفقیت ثبت شد.', [
            'comment' => new ReportCommentResource($this->comments->create($report, $request->validated(), $request->user())),
        ], 201);
    }

    public function update(UpdateTaskCommentRequest $request, Report $report, int $comment): JsonResponse
    {
        $comment = $this->findComment($report, $comment);
        Gate::authorize('manageComment', [$report, $comment]);

        return $this->success('دیدگاه با موفقیت بروزرسانی شد.', [
            'comment' => new ReportCommentResource($this->comments->update($comment, $request->validated('body'), $request->user(), $request->validated())),
        ]);
    }

    public function destroy(Report $report, int $comment): JsonResponse
    {
        $comment = $this->findComment($report, $comment);
        Gate::authorize('manageComment', [$report, $comment]);
        $this->comments->delete($comment);

        return $this->success('دیدگاه با موفقیت حذف شد.');
    }

    public function reaction(ReactToTaskCommentRequest $request, Report $report, int $comment): JsonResponse
    {
        $comment = $this->findComment($report, $comment);
        Gate::authorize('reactComment', [$report, $comment]);
        $reaction = $request->validated('reaction');
        $reactions = $this->comments->react($report, $comment, $reaction, $request->user());

        return $this->success($reaction === null ? 'واکنش حذف شد.' : 'واکنش با موفقیت ثبت شد.', ['reactions' => $reactions]);
    }

    private function findComment(Report $report, int $id): TaskComment
    {
        Gate::authorize('view', $report);

        return $report->comments()->findOrFail($id);
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
