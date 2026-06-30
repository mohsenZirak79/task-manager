<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\IdentifyRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthOtp;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
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
        if ($request->string('purpose')->toString() === AuthOtp::PURPOSE_LOGIN) {
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

        $this->authService->verifyOtpForAction(
            $request->string('identifier')->toString(),
            $request->string('purpose')->toString(),
            $request->string('code')->toString(),
        );

        return $this->success('کد تایید شد.');
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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success('خروج با موفقیت انجام شد.');
    }

    private function success(string $message, array $data = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
