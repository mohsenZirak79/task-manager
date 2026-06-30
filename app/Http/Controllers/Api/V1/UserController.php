<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AuthService $authService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request->only(['search', 'is_active', 'per_page']));

        return $this->success('لیست کاربران', [
            'items' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated(), $request->user());

        return $this->success('کاربر با موفقیت ایجاد شد.', [
            'user' => new UserResource($user),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success('جزئیات کاربر', [
            'user' => new UserResource($user->load('specialDates')),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated(), $request->user());

        return $this->success('کاربر با موفقیت بروزرسانی شد.', [
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return $this->success('کاربر با موفقیت حذف شد.');
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $user = $this->userService->activate($user, $request->user());

        return $this->success('کاربر فعال شد.', [
            'user' => new UserResource($user),
        ]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $user = $this->userService->deactivate($user, $request->user());

        return $this->success('کاربر غیرفعال شد.', [
            'user' => new UserResource($user),
        ]);
    }

    public function sendInvite(Request $request, User $user): JsonResponse
    {
        $data = $this->authService->createSetPasswordOtp($user, $request->ip(), $request->userAgent());

        return $this->success('دعوت‌نامه ارسال شد.', $data);
    }

    public function resetPassword(AdminResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->resetPassword($user, $request->validated('password'), $request->user());

        return $this->success('رمز عبور کاربر تغییر کرد.', [
            'user' => new UserResource($user),
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
