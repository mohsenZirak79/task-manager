<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EligibleReportUsersRequest;
use App\Http\Requests\IndexReportRequest;
use App\Http\Requests\StoreReportRequest;
use App\Http\Requests\UpdateReportRequest;
use App\Http\Resources\ReportResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function index(IndexReportRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Report::class);
        $reports = $this->reportService->paginate($request->validated(), $request->user());

        return $this->success('لیست گزارش‌ها', [
            'items' => ReportResource::collection($reports->items()),
            'meta' => $this->pagination($reports),
        ]);
    }

    public function eligibleUsers(EligibleReportUsersRequest $request): JsonResponse
    {
        $users = $this->reportService->eligibleUsers($request->validated());

        return $this->success('کاربران مجاز گزارش', [
            'items' => UserSummaryResource::collection($users->items()),
            'meta' => $this->pagination($users),
        ]);
    }

    public function store(StoreReportRequest $request, Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $report = $this->reportService->create($meeting, $resolution, $request->validated(), $request->user());

        return $this->success('گزارش با موفقیت ثبت شد.', ['report' => new ReportResource($report)], 201);
    }

    public function storeStandalone(StoreReportRequest $request): JsonResponse
    {
        Gate::authorize('create', Report::class);
        $report = $this->reportService->createStandalone($request->validated(), $request->user());

        return $this->success('گزارش با موفقیت ثبت شد.', ['report' => new ReportResource($report)], 201);
    }

    public function showForResolution(Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        if ($resolution->meeting_id !== $meeting->id) {
            abort(404);
        }
        $report = $resolution->report()->firstOrFail();
        Gate::authorize('view', $report);

        return $this->success('جزئیات گزارش مصوبه', ['report' => new ReportResource($this->reportService->find($report))]);
    }

    public function show(Report $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return $this->success('جزئیات گزارش', ['report' => new ReportResource($this->reportService->find($report))]);
    }

    public function update(UpdateReportRequest $request, Report $report): JsonResponse
    {
        Gate::authorize('update', $report);
        $report = $this->reportService->update($report, $request->validated(), $request->user());

        return $this->success('گزارش با موفقیت بروزرسانی شد.', ['report' => new ReportResource($report)]);
    }

    public function send(Report $report): JsonResponse
    {
        Gate::authorize('update', $report);

        return $this->success('گزارش با موفقیت ارسال شد.', ['report' => new ReportResource($this->reportService->send($report))]);
    }

    public function markViewed(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return $this->success('مشاهده گزارش با موفقیت ثبت شد.', ['report' => new ReportResource($this->reportService->markViewed($report, $request->user()))]);
    }

    public function destroy(Report $report): JsonResponse
    {
        Gate::authorize('delete', $report);
        $this->reportService->delete($report);

        return $this->success('گزارش با موفقیت حذف شد.');
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
