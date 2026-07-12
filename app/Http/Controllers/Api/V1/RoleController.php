<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->service->paginate($request->only(['search', 'is_active', 'level', 'per_page']));

        return $this->list('لیست نقش‌ها', $p);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return $this->ok('نقش با موفقیت ایجاد شد.', new RoleResource($this->service->create($request->validated())), 201);
    }

    public function show(Role $role): JsonResponse
    {
        return $this->ok('جزئیات نقش', new RoleResource($role));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        return $this->ok('نقش با موفقیت بروزرسانی شد.', new RoleResource($this->service->update($role, $request->validated())));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->service->delete($role);

        return $this->ok('نقش با موفقیت حذف شد.');
    }

    public function activate(Role $role): JsonResponse
    {
        return $this->ok('نقش فعال شد.', new RoleResource($this->service->activate($role)));
    }

    public function deactivate(Role $role): JsonResponse
    {
        return $this->ok('نقش غیرفعال شد.', new RoleResource($this->service->deactivate($role)));
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function list(string $message, $p): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => RoleResource::collection($p->items()), 'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }
}
