<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachUserRoleRequest;
use App\Http\Resources\UserRoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function __construct(private readonly UserRoleService $service) {}

    public function index(User $user): JsonResponse
    {
        return $this->ok('لیست نقش‌های کاربر', UserRoleResource::collection($this->service->all($user)));
    }

    public function store(AttachUserRoleRequest $request, User $user): JsonResponse
    {
        return $this->ok('نقش با موفقیت به کاربر متصل شد.', new UserRoleResource($this->service->attach($user, $request->validated())), 201);
    }

    public function destroy(User $user, Role $role): JsonResponse
    {
        return $this->ok('نقش کاربر غیرفعال شد.', new UserRoleResource($this->service->deactivate($user, $role)));
    }

    public function activate(User $user, Role $role): JsonResponse
    {
        return $this->ok('نقش کاربر فعال شد.', new UserRoleResource($this->service->activate($user, $role)));
    }

    public function deactivate(User $user, Role $role): JsonResponse
    {
        return $this->ok('نقش کاربر غیرفعال شد.', new UserRoleResource($this->service->deactivate($user, $role)));
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
