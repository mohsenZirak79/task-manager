<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRelationRequest;
use App\Http\Requests\UpdateRoleRelationRequest;
use App\Http\Resources\RoleRelationResource;
use App\Models\RoleRelation;
use App\Services\RoleRelationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleRelationController extends Controller
{
    public function __construct(private readonly RoleRelationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->service->paginate($request->only(['relation_type', 'parent_role_id', 'child_role_id', 'per_page']));

        return $this->list($p);
    }

    public function store(StoreRoleRelationRequest $request): JsonResponse
    {
        return $this->ok('ارتباط نقش با موفقیت ایجاد شد.', new RoleRelationResource($this->service->create($request->validated())), 201);
    }

    public function show(RoleRelation $roleRelation): JsonResponse
    {
        return $this->ok('جزئیات ارتباط نقش', new RoleRelationResource($roleRelation->load(['parentRole', 'childRole'])));
    }

    public function update(UpdateRoleRelationRequest $request, RoleRelation $roleRelation): JsonResponse
    {
        return $this->ok('ارتباط نقش با موفقیت بروزرسانی شد.', new RoleRelationResource($this->service->update($roleRelation, $request->validated())));
    }

    public function destroy(RoleRelation $roleRelation): JsonResponse
    {
        $this->service->delete($roleRelation);

        return $this->ok('ارتباط نقش با موفقیت حذف شد.');
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function list($p): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'لیست ارتباط نقش‌ها', 'data' => RoleRelationResource::collection($p->items()), 'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }
}
