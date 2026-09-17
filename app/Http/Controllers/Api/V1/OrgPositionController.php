<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailableOrgPositionUsersRequest;
use App\Http\Requests\StoreOrgPositionRequest;
use App\Http\Requests\UpdateOrgPositionRequest;
use App\Http\Resources\OrgPositionResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\OrgPosition;
use App\Services\OrgPositionService;
use Illuminate\Http\JsonResponse;

class OrgPositionController extends Controller
{
    public function __construct(private readonly OrgPositionService $service) {}

    public function index(): JsonResponse
    {
        return $this->success('ساختار سازمانی', ['org_positions' => OrgPositionResource::collection($this->service->tree())]);
    }

    public function store(StoreOrgPositionRequest $request): JsonResponse
    {
        return $this->success('جایگاه با موفقیت ایجاد شد.', ['org_position' => new OrgPositionResource($this->service->create($request->validated()))], 201);
    }

    public function show(OrgPosition $orgPosition): JsonResponse
    {
        return $this->success('جزئیات جایگاه', ['org_position' => new OrgPositionResource($this->service->find($orgPosition))]);
    }

    public function update(UpdateOrgPositionRequest $request, OrgPosition $orgPosition): JsonResponse
    {
        return $this->success('جایگاه با موفقیت بروزرسانی شد.', ['org_position' => new OrgPositionResource($this->service->update($orgPosition, $request->validated()))]);
    }

    public function destroy(OrgPosition $orgPosition): JsonResponse
    {
        $this->service->delete($orgPosition);

        return $this->success('جایگاه با موفقیت حذف شد.');
    }

    public function availableUsers(AvailableOrgPositionUsersRequest $request): JsonResponse
    {
        return $this->success('کاربران قابل انتصاب', ['users' => UserSummaryResource::collection($this->service->availableUsers($request->validated('search')))]);
    }

    private function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
