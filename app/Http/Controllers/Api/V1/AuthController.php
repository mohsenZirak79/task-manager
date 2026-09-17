<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\IdentifyRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserService $userService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        abort_unless(config('auth_flow.registration_enabled'), 403, 'ثبت‌نام عمومی غیرفعال است.');

        $user = $this->userService->register($request->validated());

        return $this->success('ثبت‌نام با موفقیت انجام شد.', [
            'user' => new UserResource($user),
        ], 201);
    }

    public function identify(IdentifyRequest $request): JsonResponse
    {
        $this->authService->identify($request->string('identifier')->toString());

        return $this->success('ادامه ورود', [
            'login_methods' => ['password', 'otp'],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
        );

        return $this->success('ورود با موفقیت انجام شد.', [
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]);
    }

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $data = $this->authService->sendOtp(
            $request->string('identifier')->toString(),
            $request->string('purpose')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return $this->success('در صورت معتبر بودن اطلاعات، کد تایید ارسال می‌شود.', $data);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyOtpForLogin(
            $request->string('identifier')->toString(),
            $request->string('purpose')->toString(),
            $request->string('code')->toString(),
        );

        return $this->success('ورود با موفقیت انجام شد.', [
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $data = $this->authService->forgotPassword(
            $request->string('identifier')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return $this->success('در صورت معتبر بودن اطلاعات، کد بازیابی ارسال می‌شود.', $data);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword(
            $request->string('identifier')->toString(),
            $request->string('purpose')->toString(),
            $request->string('code')->toString(),
            $request->string('password')->toString(),
        );

        return $this->success('رمز عبور با موفقیت تغییر کرد.');
    }

    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        $this->authService->setPassword(
            $request->string('identifier')->toString(),
            $request->string('purpose')->toString(),
            $request->string('code')->toString(),
            $request->string('password')->toString(),
        );

        return $this->success('رمز عبور با موفقیت تنظیم شد.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success('خروج با موفقیت انجام شد.');
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
