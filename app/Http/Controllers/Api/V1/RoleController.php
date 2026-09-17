<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailableRoleUsersRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\RoleUserResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roleService) {}

    public function index(): JsonResponse
    {
        return $this->success('ساختار سازمانی', [
            'roles' => RoleResource::collection($this->roleService->tree()),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return $this->success('نقش با موفقیت ایجاد شد.', [
            'role' => new RoleResource($role),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return $this->success('جزئیات نقش', [
            'role' => new RoleResource($this->roleService->find($role)),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->update($role, $request->validated());

        return $this->success('نقش با موفقیت بروزرسانی شد.', [
            'role' => new RoleResource($role),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return $this->success('نقش با موفقیت حذف شد.');
    }

    public function availableUsers(AvailableRoleUsersRequest $request): JsonResponse
    {
        return $this->success('کاربران قابل انتصاب', [
            'users' => RoleUserResource::collection($this->roleService->availableUsers(
                $request->validated('search'),
                $request->integer('role_id') ?: null,
            )),
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
