<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OtpDeliveryService
{
    public function assertAvailable(): void
    {
        $driver = config('auth_flow.otp.driver');

        if ($driver === 'log') {
            if (app()->environment('production')) {
                throw new HttpException(503, 'ارسال کد تایید در محیط production پیکربندی نشده است.');
            }

            return;
        }

        if ($driver === 'sms') {
            throw new HttpException(503, 'سرویس پیامک هنوز پیکربندی نشده است.');
        }

        throw new HttpException(503, 'روش ارسال کد تایید معتبر نیست.');
    }

    public function deliver(User $user, string $code, string $purpose): void
    {
        $this->assertAvailable();

        if (config('auth_flow.otp.driver') === 'log') {
            Log::info('OTP generated for test delivery.', [
                'user_id' => $user->id,
                'identifier' => $user->mobile,
                'purpose' => $purpose,
                'code' => $code,
            ]);

            return;
        }
    }
}
