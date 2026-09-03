<?php

namespace App\Services;

use App\Models\AuthOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthService
{
    private const LOGIN_ERROR = 'اطلاعات ورود نامعتبر است.';

    private const OTP_ERROR = 'کد وارد شده نامعتبر است.';

    public function __construct(private readonly OtpDeliveryService $otpDelivery) {}

    public function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = $this->normalizeIdentifier($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', $identifier)->first();
        }

        $user = User::query()->where('mobile', $identifier)->first();

        return $user ?: User::query()->where('org_code', $identifier)->first();
    }

    public function identify(string $identifier): User
    {
        return $this->activeUserOrFail($identifier);
    }

    public function login(string $identifier, string $password): array
    {
        $user = $this->activeUserOrFail($identifier);

        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw new HttpException(401, self::LOGIN_ERROR);
        }

        return $this->issueToken($user);
    }

    public function sendOtp(string $identifier, string $purpose, ?string $ip = null, ?string $userAgent = null): array
    {
        $user = $this->activeUserOrFail($identifier);
        $this->otpDelivery->assertAvailable();
        $code = $this->generateOtpCode();

        AuthOtp::query()->create([
            'user_id' => $user->id,
            'identifier' => $this->normalizeIdentifier($identifier),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('auth_flow.otp.expires_minutes')),
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        $this->otpDelivery->deliver($user, $code, $purpose);

        return [];
    }

    public function verifyOtp(string $identifier, string $purpose, string $code): AuthOtp
    {
        $otp = AuthOtp::query()
            ->where('identifier', $this->normalizeIdentifier($identifier))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->lockForUpdate()
            ->latest()
            ->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
            throw new HttpException(401, self::OTP_ERROR);
        }

        if (! $otp->user || ! $otp->user->is_active) {
            throw new HttpException(403, self::LOGIN_ERROR);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw new HttpException(401, self::OTP_ERROR);
        }

        $otp->forceFill(['used_at' => now()])->save();

        return $otp;
    }

    public function verifyOtpForLogin(string $identifier, string $purpose, string $code): array
    {
        $otp = DB::transaction(fn () => $this->verifyOtp($identifier, $purpose, $code));

        return $this->issueToken($otp->user);
    }

    public function forgotPassword(string $identifier, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->otpDelivery->assertAvailable();
        $user = $this->findUserByIdentifier($identifier);

        if (! $user || ! $user->is_active) {
            return [];
        }

        return $this->sendOtp($identifier, AuthOtp::PURPOSE_FORGOT_PASSWORD, $ip, $userAgent);
    }

    public function resetPassword(string $identifier, string $purpose, string $code, string $password): User
    {
        return $this->changePasswordWithOtp($identifier, $purpose, $code, $password);
    }

    public function setPassword(string $identifier, string $purpose, string $code, string $password): User
    {
        return $this->changePasswordWithOtp($identifier, $purpose, $code, $password);
    }

    private function changePasswordWithOtp(string $identifier, string $purpose, string $code, string $password): User
    {
        return DB::transaction(function () use ($identifier, $purpose, $code, $password) {
            $otp = $this->verifyOtp($identifier, $purpose, $code);
            $user = $otp->user;

            if (! $user || ! $user->is_active) {
                throw new HttpException(403, self::LOGIN_ERROR);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
            ])->save();
            $user->tokens()->delete();

            return $user;
        });
    }

    public function createSetPasswordOtp(User $user, ?string $ip = null, ?string $userAgent = null): array
    {
        if (! $user->is_active) {
            throw new HttpException(403, 'کاربر غیرفعال است.');
        }

        return $this->sendOtp($user->mobile, AuthOtp::PURPOSE_SET_PASSWORD, $ip, $userAgent);
    }

    private function issueToken(User $user): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return [
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user->fresh(['specialDates', 'role']),
        ];
    }

    private function activeUserOrFail(string $identifier): User
    {
        $user = $this->findUserByIdentifier($identifier);

        if (! $user) {
            throw new HttpException(401, self::LOGIN_ERROR);
        }

        if (! $user->is_active) {
            throw new HttpException(403, self::LOGIN_ERROR);
        }

        return $user;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? strtolower($identifier) : $identifier;
    }

    private function generateOtpCode(): string
    {
        $fixedCode = config('auth_flow.otp.fixed_code');

        if ($fixedCode && app()->environment(['local', 'testing']) && preg_match('/^\d{6}$/', $fixedCode)) {
            return $fixedCode;
        }

        return (string) random_int(100000, 999999);
    }
}
