<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMeetingTaskRequest;
use App\Http\Requests\LinkMeetingTaskRequest;
use App\Http\Requests\StoreMeetingResolutionRequest;
use App\Http\Requests\UpdateMeetingResolutionRequest;
use App\Http\Resources\MeetingResolutionResource;
use App\Http\Resources\TaskResource;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Task;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingResolutionController extends Controller
{
    public function __construct(private readonly MeetingService $meetingService) {}

    public function store(StoreMeetingResolutionRequest $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $resolution = $this->meetingService->createResolution($meeting, $request->validated(), $request->user());

        return $this->success('مصوبه با موفقیت ثبت شد.', ['resolution' => new MeetingResolutionResource($resolution)], 201);
    }

    public function update(UpdateMeetingResolutionRequest $request, Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $resolution = $this->meetingService->updateResolution($meeting, $resolution, $request->validated(), $request->user());

        return $this->success('مصوبه با موفقیت بروزرسانی شد.', ['resolution' => new MeetingResolutionResource($resolution)]);
    }

    public function destroy(Request $request, Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $this->meetingService->deleteResolution($meeting, $resolution);

        return $this->success('مصوبه با موفقیت حذف شد.');
    }

    public function createTask(CreateMeetingTaskRequest $request, Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $resolution = $this->meetingService->createTask($meeting, $resolution, $request->validated(), $request->user());

        return $this->success('تسک مصوبه با موفقیت ایجاد شد.', [
            'resolution' => new MeetingResolutionResource($resolution),
            'task' => new TaskResource($resolution->task),
        ], 201);
    }

    public function linkTask(LinkMeetingTaskRequest $request, Meeting $meeting, MeetingResolution $resolution): JsonResponse
    {
        Gate::authorize('manageResolutions', $meeting);
        $task = Task::query()->findOrFail($request->integer('task_id'));
        $resolution = $this->meetingService->linkTask($meeting, $resolution, $task, $request->user());

        return $this->success('تسک موجود با موفقیت به مصوبه متصل شد.', ['resolution' => new MeetingResolutionResource($resolution)]);
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
