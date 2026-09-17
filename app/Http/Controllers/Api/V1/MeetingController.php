<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMeetingRequest;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\SubmitMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\MeetingSummaryResource;
use App\Models\Meeting;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingController extends Controller
{
    public function __construct(private readonly MeetingService $meetingService) {}

    public function index(IndexMeetingRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Meeting::class);
        $meetings = $this->meetingService->paginate($request->validated(), $request->user());

        return $this->success('لیست صورت‌جلسه‌ها', [
            'items' => MeetingSummaryResource::collection($meetings->items()),
            'meta' => [
                'current_page' => $meetings->currentPage(),
                'last_page' => $meetings->lastPage(),
                'per_page' => $meetings->perPage(),
                'total' => $meetings->total(),
            ],
        ]);
    }

    public function store(StoreMeetingRequest $request): JsonResponse
    {
        Gate::authorize('create', Meeting::class);
        $meeting = $this->meetingService->create($request->validated(), $request->user());

        return $this->success('صورت‌جلسه با موفقیت ایجاد شد.', ['meeting' => new MeetingResource($meeting)], 201);
    }

    public function show(Meeting $meeting): JsonResponse
    {
        Gate::authorize('view', $meeting);

        return $this->success('جزئیات صورت‌جلسه', ['meeting' => new MeetingResource($this->meetingService->find($meeting))]);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('update', $meeting);
        $meeting = $this->meetingService->update($meeting, $request->validated());

        return $this->success('صورت‌جلسه با موفقیت بروزرسانی شد.', ['meeting' => new MeetingResource($meeting)]);
    }

    public function destroy(Meeting $meeting): JsonResponse
    {
        Gate::authorize('delete', $meeting);
        $this->meetingService->delete($meeting);

        return $this->success('صورت‌جلسه با موفقیت حذف شد.');
    }

    public function submit(SubmitMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('submit', $meeting);
        $meeting = $this->meetingService->submit($meeting);

        return $this->success('صورت‌جلسه با موفقیت ارسال و زمان‌بندی شد.', ['meeting' => new MeetingResource($meeting)]);
    }

    public function complete(Request $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('complete', $meeting);
        $meeting = $this->meetingService->complete($meeting);

        return $this->success('جلسه با موفقیت تکمیل و قفل شد.', ['meeting' => new MeetingResource($meeting)]);
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
